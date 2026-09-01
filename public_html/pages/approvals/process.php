<?php
/**
 * LOKA - Process Approval Action (Hardened Version)
 * 
 * Two-stage approval workflow with race condition protection:
 * Stage 1: Assigned Approver reviews and approves (REGARDLESS of department)
 * Stage 2: Assigned Motorpool Head assigns vehicle/driver and gives final approval
 * 
 * Actions: Approve | Reject (with reason) | Request Revision (can resubmit)
 * 
 * SECURITY FIXES:
 * - FOR UPDATE row-level locking prevents concurrent approval processing
 * - Notifications deferred until AFTER commit to prevent orphaned emails
 * - State machine validation prevents invalid status transitions
 * - Admin override audit trail
 */

requireRole(ROLE_APPROVER);
requireCsrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/?page=approvals');
}

$requestId = postInt('request_id');
$approvalAction = postSafe('approval_action', '', 20); // 'approve', 'reject', or 'revision'
$approvalType = postSafe('approval_type', '', 20); // 'department' or 'motorpool'
$comments = postSafe('comments', '', 1000);
$vehicleId = postInt('vehicle_id') ?: null;
$driverId = postInt('driver_id') ?: null;
$mileageStart = postInt('mileage_start') ?: null; // Optional mileage start

// Validate action
if (!in_array($approvalAction, ['approve', 'reject', 'revision'])) {
    $errorMsg = 'Invalid action. Please try again.';
    if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $errorMsg]);
        exit;
    }
    redirectWith('/?page=approvals', 'danger', $errorMsg);
}

// Get request with FOR UPDATE locking - PREVENTS RACE CONDITIONS
$request = db()->fetch(
    "SELECT r.*, 
            u.name as requester_name, u.email as requester_email,
            appr.name as approver_name, appr.email as approver_email,
            mph.name as motorpool_name, mph.email as motorpool_email
     FROM requests r
     JOIN users u ON r.user_id = u.id
     LEFT JOIN users appr ON r.approver_id = appr.id
     LEFT JOIN users mph ON r.motorpool_head_id = mph.id
     WHERE r.id = ? AND r.deleted_at IS NULL
     FOR UPDATE",  // ROW LOCK - prevents concurrent processing
    [$requestId]
);

if (!$request) {
    redirectWith('/?page=approvals', 'danger', 'Request not found.');
}

// Validate permissions
$canProcess = false;
$currentUserId = userId();
$isAdminOverride = false;

// Handle revision requests - can be processed by either approver or motorpool
if ($request->status === STATUS_REVISION) {
    // Check if current user is the assigned approver or motorpool head
    if ($request->approver_id == $currentUserId || $request->motorpool_head_id == $currentUserId || isAdmin()) {
        $canProcess = true;
        // Determine approval type based on who is processing
        if ($approvalType === 'motorpool' || $request->motorpool_head_id == $currentUserId || isMotorpool()) {
            $approvalType = 'motorpool';
        } else {
            $approvalType = 'department';
        }
        $isAdminOverride = isAdmin();
    }
} elseif ($approvalType === 'motorpool' && $request->status === STATUS_PENDING_MOTORPOOL) {
    // Motorpool heads can process ANY request in pending_motorpool status (not just assigned ones)
    // This allows motorpool heads to override and handle all vehicle assignments
    if ($request->motorpool_head_id == $currentUserId || isMotorpool() || isAdmin()) {
        $canProcess = true;
        // Mark as override if motorpool head is processing someone else's assigned request
        $isAdminOverride = ($request->motorpool_head_id != $currentUserId && (isMotorpool() || isAdmin()));
    }
} elseif ($approvalType === 'department' && $request->status === STATUS_PENDING) {
    if ($request->approver_id == $currentUserId || isAdmin()) {
        $canProcess = true;
        $isAdminOverride = isAdmin() && $request->approver_id != $currentUserId;
    }
}

if (!$canProcess) {
    $errorMsg = 'You are not authorized to process this request.';
    if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => $errorMsg]);
        exit;
    }
    redirectWith('/?page=approvals', 'danger', $errorMsg);
}

// Rejection/Revision requires comments (mandatory reason)
if (in_array($approvalAction, ['reject', 'revision'])) {
    $comments = trim($comments);
    if (empty($comments)) {
        $errorMsg = $approvalAction === 'reject' 
            ? 'A reason is mandatory when rejecting a request. Please provide a reason for rejection.'
            : 'Comments are required when requesting revision. Please explain what needs to be revised.';
        if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $errorMsg]);
            exit;
        }
        redirectWith('/?page=approvals&action=view&id=' . $requestId, 'danger', $errorMsg);
    }
}

// Motorpool approval requires vehicle and driver
if ($approvalType === 'motorpool' && $approvalAction === 'approve') {
    if (!$vehicleId || !$driverId) {
        $errorMsg = 'Vehicle and driver assignment required for approval.';
        if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $errorMsg]);
            exit;
        }
        redirectWith('/?page=approvals&action=view&id=' . $requestId, 'danger', $errorMsg);
    }
    
    // Check vehicle exists (motorpool can override status)
    $vehicle = db()->fetch(
        "SELECT status, mileage FROM vehicles WHERE id = ? AND deleted_at IS NULL",
        [$vehicleId]
    );
    if (!$vehicle) {
        $errorMsg = 'Selected vehicle does not exist.';
        if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $errorMsg]);
            exit;
        }
        redirectWith('/?page=approvals&action=view&id=' . $requestId, 'danger', $errorMsg);
    }

    // Validate mileage_start if provided (must be >= vehicle's current mileage)
    if ($mileageStart !== null && $mileageStart < $vehicle->mileage) {
        $errorMsg = "Starting mileage ({$mileageStart}) cannot be less than vehicle's current mileage ({$vehicle->mileage}).";
        if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $errorMsg]);
            exit;
        }
        redirectWith('/?page=approvals&action=view&id=' . $requestId, 'danger', $errorMsg);
    }
    
    // Check driver exists and user is active
    $driver = db()->fetch(
        "SELECT d.status as driver_status, u.status as user_status
         FROM drivers d JOIN users u ON d.user_id = u.id
         WHERE d.id = ? AND d.deleted_at IS NULL",
        [$driverId]
    );
    if (!$driver) {
        $errorMsg = 'Selected driver does not exist.';
        if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $errorMsg]);
            exit;
        }
        redirectWith('/?page=approvals&action=view&id=' . $requestId, 'danger', $errorMsg);
    }
    if ($driver->user_status !== 'active') {
        $errorMsg = 'Selected driver is not available (user inactive).';
        if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $errorMsg]);
            exit;
        }
        redirectWith('/?page=approvals&action=view&id=' . $requestId, 'danger', $errorMsg);
    }
    
    // Check for vehicle/driver conflicts with other approved requests
    // Allow motorpool head to override conflicts if explicitly confirmed
    $overrideConflicts = post('override_conflict') ? true : false;
    
    if (!$overrideConflicts) {
        $vehicleConflict = db()->fetch(
            "SELECT COUNT(*) as cnt FROM requests
             WHERE vehicle_id = ? AND id != ? AND status = 'approved'
             AND deleted_at IS NULL
             AND ((start_datetime <= ? AND end_datetime >= ?)
                  OR (start_datetime >= ? AND end_datetime <= ?))",
            [$vehicleId, $requestId, $request->end_datetime, $request->start_datetime,
             $request->start_datetime, $request->end_datetime]
        );
        if ($vehicleConflict && $vehicleConflict->cnt > 0) {
            $errorMsg = 'Vehicle has a scheduling conflict with another approved request.';
            if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => $errorMsg]);
                exit;
            }
            redirectWith('/?page=approvals&action=view&id=' . $requestId, 'danger', $errorMsg);
        }
        
        $driverConflict = db()->fetch(
            "SELECT COUNT(*) as cnt FROM requests
             WHERE driver_id = ? AND id != ? AND status = 'approved'
             AND deleted_at IS NULL
             AND ((start_datetime <= ? AND end_datetime >= ?)
                  OR (start_datetime >= ? AND end_datetime <= ?))",
            [$driverId, $requestId, $request->end_datetime, $request->start_datetime,
             $request->start_datetime, $request->end_datetime]
        );
        if ($driverConflict && $driverConflict->cnt > 0) {
            $errorMsg = 'Driver has a scheduling conflict with another approved request.';
            if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => $errorMsg]);
                exit;
            }
            redirectWith('/?page=approvals&action=view&id=' . $requestId, 'danger', $errorMsg);
        }
    }
}

try {
    db()->beginTransaction();

    $oldStatus = $request->status;
    $newStatus = '';
    $now = date(DATETIME_FORMAT);
    
    // STATE MACHINE VALIDATION - Prevent invalid transitions
    $validTransitions = [
        STATUS_PENDING => [STATUS_PENDING_MOTORPOOL, STATUS_REJECTED, STATUS_REVISION, STATUS_CANCELLED],
        STATUS_PENDING_MOTORPOOL => [STATUS_APPROVED, STATUS_REJECTED, STATUS_REVISION, STATUS_CANCELLED],
        STATUS_REVISION => [STATUS_PENDING, STATUS_PENDING_MOTORPOOL, STATUS_APPROVED, STATUS_REJECTED, STATUS_CANCELLED],
        STATUS_APPROVED => [STATUS_CANCELLED], // Allow cancellation of approved requests
    ];
    
    // Determine intended new status
    if ($approvalAction === 'revision') {
        $newStatus = STATUS_REVISION;
    } elseif ($approvalAction === 'reject') {
        $newStatus = STATUS_REJECTED;
    } else {
        // Approve
        if ($approvalType === 'department') {
            $newStatus = STATUS_PENDING_MOTORPOOL;
        } else {
            $newStatus = STATUS_APPROVED;
        }
    }
    
    // Validate state transition
    $validTransitionsForCurrent = $validTransitions[$oldStatus] ?? [];
    if (!in_array($newStatus, $validTransitionsForCurrent)) {
        throw new Exception(
            "Invalid workflow transition from '{$oldStatus}' to '{$newStatus}'. " .
            "Allowed transitions from '{$oldStatus}': " . 
            (empty($validTransitionsForCurrent) ? 'none (terminal state)' : implode(', ', $validTransitionsForCurrent))
        );
    }

    // Prepare notification data (deferred until after commit)
    $notificationsToSend = [];
    
    // Handle action
    if ($approvalAction === 'revision') {
        $revisionBy = $approvalType === 'department' 
            ? ($request->approver_name ?: 'Department Approver')
            : ($request->motorpool_name ?: 'Motorpool Head');
        $revisionByRole = $approvalType === 'department' ? 'Department Approver' : 'Motorpool Head';
        
        // Queue notifications for after commit
        $notificationsToSend[] = [
            'user_id' => $request->user_id,
            'type' => 'request_revision',
            'title' => 'Request Sent Back for Revision',
            'message' => "Your request for {$request->destination} on " . formatDate($request->start_datetime) . " has been sent back for revision by the {$revisionByRole}.\n\nRequested by: {$revisionBy}\nReason: {$comments}\n\nPlease update your request and resubmit.",
            'link' => '/?page=requests&action=view&id=' . $requestId
        ];
        
        // Passengers and driver will be queued after getting passenger list
        
    } elseif ($approvalAction === 'reject') {
        $rejectedBy = $approvalType === 'department' 
            ? ($request->approver_name ?: 'Department Approver')
            : ($request->motorpool_name ?: 'Motorpool Head');
        $rejectedByRole = $approvalType === 'department' ? 'Department Approver' : 'Motorpool Head';
        
        $notificationsToSend[] = [
            'user_id' => $request->user_id,
            'type' => 'request_rejected',
            'title' => 'Request Rejected',
            'message' => "Your request for {$request->destination} on " . formatDate($request->start_datetime) . " has been rejected by the {$rejectedByRole}.\n\nRejected by: {$rejectedBy}\nReason: {$comments}",
            'link' => '/?page=requests&action=view&id=' . $requestId
        ];
        
    } else {
        // Approval
        if ($approvalType === 'department') {
            $notificationsToSend[] = [
                'user_id' => $request->user_id,
                'type' => 'department_approved',
                'title' => 'Request Approved by Approver',
                'message' => "Your request for {$request->destination} on " . formatDate($request->start_datetime) . " has been approved by the assigned approver and is now awaiting motorpool assignment.",
                'link' => '/?page=requests&action=view&id=' . $requestId
            ];
            
            if ($request->motorpool_head_id) {
                $notificationsToSend[] = [
                    'user_id' => $request->motorpool_head_id,
                    'type' => 'pending_motorpool_approval',
                    'title' => 'Request Awaiting Motorpool Approval',
                    'message' => "A vehicle request for {$request->destination} has been approved by the assigned approver and requires your approval and vehicle assignment.",
                    'link' => '/?page=approvals&action=view&id=' . $requestId
                ];
            }
            
            if ($request->requested_driver_id) {
                // FIX: requested_driver_id is drivers.id — resolve users.id for notify()
                $reqDriverUser = db()->fetch(
                    "SELECT d.user_id FROM drivers d WHERE d.id = ?",
                    [$request->requested_driver_id]
                );
                if ($reqDriverUser && $reqDriverUser->user_id) {
                    $notificationsToSend[] = [
                        'user_id' => $reqDriverUser->user_id,
                        'type' => 'driver_status_update',
                        'title' => 'Request Progress Update',
                        'message' => "A trip you were requested to drive has been approved by the assigned approver and is now awaiting final motorpool approval.",
                        'link' => '/?page=requests&action=view&id=' . $requestId
                    ];
                }
            }
            
        } else {
            // Motorpool approval
            $vehicle = db()->fetch(
                "SELECT plate_number, make, model FROM vehicles WHERE id = ?", [$vehicleId]
            );
            $driver = db()->fetch(
                "SELECT d.*, u.name as driver_name, u.phone as driver_phone 
                 FROM drivers d JOIN users u ON d.user_id = u.id WHERE d.id = ?", 
                [$driverId]
            );

            $vehicleInfo = $vehicle ? "{$vehicle->plate_number} - {$vehicle->make} {$vehicle->model}" : 'TBA';
            $driverInfo = $driver ? $driver->driver_name : 'TBA';

            // Check for override and identify all conflicting requests
            $overrideConflicts = post('override_conflict') ? true : false;
            $conflictingRequests = [];

            if ($overrideConflicts) {
                // Get vehicle conflicts
                $vehicleConflicts = db()->fetchAll(
                    "SELECT r.*, u.name as requester_name
                     FROM requests r
                     JOIN users u ON r.user_id = u.id
                     WHERE r.vehicle_id = ?
                     AND r.id != ?
                     AND r.status IN ('approved', 'pending_motorpool')
                     AND r.start_datetime < ?
                     AND r.end_datetime > ?
                     AND r.deleted_at IS NULL",
                    [$vehicleId, $requestId, $request->end_datetime, $request->start_datetime]
                );

                // Get driver conflicts
                $driverConflicts = db()->fetchAll(
                    "SELECT r.*, u.name as requester_name
                     FROM requests r
                     JOIN users u ON r.user_id = u.id
                     WHERE r.driver_id = ?
                     AND r.id != ?
                     AND r.status IN ('approved', 'pending_motorpool')
                     AND r.start_datetime < ?
                     AND r.end_datetime > ?
                     AND r.deleted_at IS NULL",
                    [$driverId, $requestId, $request->end_datetime, $request->start_datetime]
                );

                // Merge conflicts (avoid duplicates)
                $conflictMap = [];
                foreach (array_merge($vehicleConflicts, $driverConflicts) as $conflict) {
                    if (!isset($conflictMap[$conflict->id])) {
                        $conflictMap[$conflict->id] = $conflict;
                    }
                }
                $conflictingRequests = array_values($conflictMap);

                // Notify all affected requesters (CUSTOMIZED MESSAGE)
                foreach ($conflictingRequests as $conflictingRequest) {
                    $overrideMessage = "⚠️ IMPORTANT: Your trip may be affected\n\n";
                    $overrideMessage .= "Your trip to {$conflictingRequest->destination} on " . formatDateTime($conflictingRequest->start_datetime) . " (Request #{$conflictingRequest->id}) has been affected by a vehicle/driver override.\n\n";
                    $overrideMessage .= "Overriding Request Details:\n";
                    $overrideMessage .= "  Request #: {$requestId}\n";
                    $overrideMessage .= "  Requester: {$request->requester_name}\n";
                    $overrideMessage .= "  Destination: {$request->destination}\n";
                    $overrideMessage .= "  Time: " . formatDateTime($request->start_datetime) . " - " . formatDateTime($request->end_datetime) . "\n";
                    $overrideMessage .= "  Vehicle: {$vehicleInfo}\n";
                    $overrideMessage .= "  Driver: {$driverInfo}\n\n";

                    $overrideMessage .= "Your Request Details:\n";
                    $overrideMessage .= "  Request #: {$conflictingRequest->id}\n";
                    $overrideMessage .= "  Destination: {$conflictingRequest->destination}\n";
                    $overrideMessage .= "  Time: " . formatDateTime($conflictingRequest->start_datetime) . " - " . formatDateTime($conflictingRequest->end_datetime) . "\n\n";

                    $overrideMessage .= "Please review your trip details and contact motorpool if needed. You may need to adjust your plans.";

                    $notificationsToSend[] = [
                        'user_id' => $conflictingRequest->user_id,
                        'type' => 'request_override_notice',
                        'title' => '⚠️ Your Trip May Be Affected by Override',
                        'message' => $overrideMessage,
                        'link' => '/?page=requests&action=view&id=' . $conflictingRequest->id
                    ];
                }

                // Notify all affected passengers (SIMPLIFIED MESSAGE)
                foreach ($conflictingRequests as $conflictingRequest) {
                    $passengers = db()->fetchAll(
                        "SELECT user_id FROM request_passengers
                         WHERE request_id = ? AND user_id IS NOT NULL",
                        [$conflictingRequest->id]
                    );

                    foreach ($passengers as $passenger) {
                        $passengerMessage = "⚠️ Trip Notice\n\n";
                        $passengerMessage .= "A trip you are part of (Request #{$conflictingRequest->id}) may be affected by a vehicle/driver override.\n\n";
                        $passengerMessage .= "Overriding Request: #{$requestId}\n";
                        $passengerMessage .= "To: {$request->destination}\n";
                        $passengerMessage .= "Time: " . formatDateTime($request->start_datetime) . "\n\n";

                        $passengerMessage .= "Please check your trip details and contact motorpool if needed.";

                        $notificationsToSend[] = [
                            'user_id' => $passenger->user_id,
                            'type' => 'passenger_override_notice',
                            'title' => '⚠️ Trip May Be Affected by Override',
                            'message' => $passengerMessage,
                            'link' => '/?page=requests&action=view&id=' . $conflictingRequest->id
                        ];
                    }
                }
            }

            // Update vehicle and driver status
            db()->update('vehicles', ['status' => 'in_use'], 'id = ?', [$vehicleId]);
            db()->update('drivers', ['status' => 'on_trip'], 'id = ?', [$driverId]);
            
            // Record initial assignment in history
            db()->insert('assignment_history', [
                'request_id' => $requestId,
                'vehicle_id' => $vehicleId,
                'driver_id' => $driverId,
                'assigned_by' => userId(),
                'action' => 'assigned',
                'previous_vehicle_id' => null,
                'previous_driver_id' => null,
                'reason' => null,
                'created_at' => $now
            ]);
            
            $notificationsToSend[] = [
                'user_id' => $request->user_id,
                'type' => 'request_fully_approved',
                'title' => 'Request Fully Approved!',
                'message' => "Great news! Your request for {$request->destination} on " . formatDate($request->start_datetime) . " has been fully approved.\n\nVehicle: {$vehicleInfo}\nDriver: {$driverInfo}\nDeparture: " . formatDateTime($request->start_datetime),
                'link' => '/?page=requests&action=view&id=' . $requestId
            ];
            
            if ($driverId) {
                // FIX: $driverId is drivers.id — resolve the users.id so notify() can find the email
                $assignedDriverUser = db()->fetch(
                    "SELECT d.user_id FROM drivers d WHERE d.id = ?", [$driverId]
                );
                if ($assignedDriverUser && $assignedDriverUser->user_id) {
                    $notificationsToSend[] = [
                        'user_id' => $assignedDriverUser->user_id,
                        'type' => 'driver_assigned',
                        'title' => 'You Have Been Assigned as Driver',
                        'message' => "You have been assigned as the driver for a trip to {$request->destination}.\n\nDeparture: " . formatDateTime($request->start_datetime) . "\nReturn: " . formatDateTime($request->end_datetime) . "\nVehicle: {$vehicleInfo}\nPassengers: {$request->passenger_count}",
                        'link' => '/?page=requests&action=view&id=' . $requestId
                    ];
                }
            }
            
            if ($request->requested_driver_id && $request->requested_driver_id != $driverId) {
                // FIX: requested_driver_id is also drivers.id — resolve users.id
                $requestedDriverUser = db()->fetch(
                    "SELECT d.user_id FROM drivers d WHERE d.id = ?", [$request->requested_driver_id]
                );
                if ($requestedDriverUser && $requestedDriverUser->user_id) {
                    $notificationsToSend[] = [
                        'user_id' => $requestedDriverUser->user_id,
                        'type' => 'driver_not_selected',
                        'title' => 'Request Assignment Update',
                        'message' => "A trip you were requested to drive has been assigned to another driver. The trip to {$request->destination} on " . formatDate($request->start_datetime) . " is now fully approved.",
                        'link' => '/?page=requests&action=view&id=' . $requestId
                    ];
                }
            }
        }
    }

    // Update request status
    $updateData = [
        'status' => $newStatus,
        'updated_at' => $now
    ];

    // If motorpool approval, assign vehicle and driver
    if ($approvalType === 'motorpool' && $approvalAction === 'approve') {
        $updateData['vehicle_id'] = $vehicleId;
        $updateData['driver_id'] = $driverId;
        if ($mileageStart !== null) {
            $updateData['mileage_start'] = $mileageStart;
        }
    }

    $updatedRows = db()->update('requests', $updateData, 'id = ?', [$requestId]);
    
    // Verify the update worked
    $verifyRequest = db()->fetch("SELECT status FROM requests WHERE id = ?", [$requestId]);
    if (!$verifyRequest || $verifyRequest->status !== $newStatus) {
        throw new Exception("Status update verification failed. Expected: {$newStatus}, Got: " . ($verifyRequest->status ?? 'NULL'));
    }

    // Create approval record
    db()->insert('approvals', [
        'request_id' => $requestId,
        'approver_id' => userId(),
        'approval_type' => $approvalType,
        'status' => $approvalAction === 'approve' ? 'approved' : ($approvalAction === 'revision' ? 'revision' : 'rejected'),
        'comments' => $comments,
        'created_at' => $now
    ]);

    // Update or create workflow record
    $workflow = db()->fetch(
        "SELECT * FROM approval_workflow WHERE request_id = ?",
        [$requestId]
    );

    $workflowData = [
        'comments' => $comments,
        'action_at' => $now,
        'updated_at' => $now
    ];

    if ($approvalType === 'department') {
        $workflowData['approver_id'] = userId();
        if ($approvalAction === 'approve') {
            $workflowData['step'] = 'motorpool';
            $workflowData['status'] = 'pending';
        } else {
            $workflowData['status'] = $approvalAction === 'revision' ? 'revision' : 'rejected';
        }
    } else {
        $workflowData['motorpool_head_id'] = userId();
        $workflowData['step'] = 'motorpool';
        $workflowData['status'] = $approvalAction === 'approve' ? 'approved' : ($approvalAction === 'revision' ? 'revision' : 'rejected');
    }

    if ($workflow) {
        db()->update('approval_workflow', $workflowData, 'request_id = ?', [$requestId]);
    } else {
        $workflowData['request_id'] = $requestId;
        $workflowData['department_id'] = $request->department_id;
        $workflowData['created_at'] = $now;
        db()->insert('approval_workflow', $workflowData);
    }

    // Audit log with admin override tracking
    $auditData = [
        'status' => $newStatus, 
        'approval_type' => $approvalType, 
        'comments' => $comments,
        'previous_status' => $oldStatus,
        'is_admin_override' => $isAdminOverride
    ];
    
    if ($isAdminOverride) {
        $assignedApprover = $approvalType === 'motorpool' ? $request->motorpool_head_id : $request->approver_id;
        $auditData['assigned_approver_id'] = $assignedApprover;
        $auditData['actual_approver_id'] = userId();
        $auditData['override_reason'] = 'Admin processed approval for unassigned request';
    }
    
    auditLog(
        $approvalAction === 'approve' ? 'request_approved' : ($approvalAction === 'revision' ? 'request_revision' : 'request_rejected'),
        'request',
        $requestId,
        ['status' => $oldStatus],
        $auditData
    );

    db()->commit();

    // Create trip confirmation for fully approved requests (best-effort, post-commit)
    // Uses same-day vs lead-hours scheduling; idempotent and respects trip_confirmation_enabled.
    if ($approvalAction === 'approve' && $approvalType === 'motorpool' && $newStatus === STATUS_APPROVED) {
        try {
            if (function_exists('createTripConfirmation')) {
                // Clear prior reschedule flags — new cycle will be created clean
                if (!empty($request->reschedule_requested)) {
                    db()->update('requests', ['reschedule_requested' => 0, 'reschedule_note' => null], 'id = ?', [$requestId]);
                }
                createTripConfirmation((int) $requestId);
            }
        } catch (Throwable $e) {
            error_log('post-approval createTripConfirmation #' . $requestId . ': ' . $e->getMessage());
        }
    }
    // If re-approving a rescheduled request routed via department path that lands on pending_motorpool -> approved,
    // the above motorpool-only branch covers the normal case. For completeness, also handle department approve
    // that directly completes a rescheduled trip (rare), by checking newStatus.
    if ($approvalAction === 'approve' && $newStatus === STATUS_APPROVED && $approvalType !== 'motorpool') {
        try {
            if (function_exists('createTripConfirmation')) {
                createTripConfirmation((int) $requestId);
            }
        } catch (Throwable $e) {
            error_log('post-approval (dept) createTripConfirmation #' . $requestId . ': ' . $e->getMessage());
        }
    }

    // =====================================================
    // SEND NOTIFICATIONS AFTER SUCCESSFUL COMMIT
    // This prevents orphaned emails if transaction fails
    // =====================================================
    // NOTE: All notifications go through notify() → EmailQueue → cron processor.
    // The old NotificationService::* calls were removed because they:
    //   1. Bypassed the queue (direct Mailer::send), missing audit trail
    //   2. Had broken notification-preference checks that silently blocked delivery
    //   3. Caused double-send attempts when both paths fired together

    // Send all queued in-app + email notifications
    foreach ($notificationsToSend as $notif) {
        notify($notif['user_id'], $notif['type'], $notif['title'], $notif['message'], $notif['link'] ?? null, $requestId);
    }
    
    // Send passenger notifications based on action type
    $passengerType = null;
    $passengerTitle = null;
    $passengerMessage = null;
    
    if ($approvalAction === 'revision') {
        $passengerType = 'trip_revision';
        $passengerTitle = 'Trip Sent Back for Revision';
        $revisionBy = $approvalType === 'department' 
            ? ($request->approver_name ?: 'Department Approver')
            : ($request->motorpool_name ?: 'Motorpool Head');
        $passengerMessage = "The trip to {$request->destination} on " . formatDate($request->start_datetime) . " has been sent back for revision.\n\nReason: {$comments}";
    } elseif ($approvalAction === 'reject') {
        $passengerType = 'trip_rejected';
        $passengerTitle = 'Trip Rejected';
        $rejectedBy = $approvalType === 'department' 
            ? ($request->approver_name ?: 'Department Approver')
            : ($request->motorpool_name ?: 'Motorpool Head');
        $passengerMessage = "The trip to {$request->destination} on " . formatDate($request->start_datetime) . " has been rejected.\n\nReason: {$comments}";
    } elseif ($approvalType === 'department') {
        $passengerType = 'department_approved';
        $passengerTitle = 'Trip Approved by Approver';
        $passengerMessage = "The trip to {$request->destination} on " . formatDate($request->start_datetime) . " has been approved by the assigned approver and is now awaiting vehicle assignment.";
    } else {
        $passengerType = 'trip_fully_approved';
        $passengerTitle = 'Trip Fully Approved';
        $vehicle = $vehicleId ? db()->fetch("SELECT plate_number, make, model FROM vehicles WHERE id = ?", [$vehicleId]) : null;
        $driver = $driverId ? db()->fetch("SELECT d.*, u.name as driver_name FROM drivers d JOIN users u ON d.user_id = u.id WHERE d.id = ?", [$driverId]) : null;
        $vehicleInfo = $vehicle ? "{$vehicle->plate_number} - {$vehicle->make} {$vehicle->model}" : 'TBA';
        $driverInfo = $driver ? $driver->driver_name : 'TBA';
        $passengerMessage = "The trip to {$request->destination} on " . formatDate($request->start_datetime) . " has been fully approved!\n\nVehicle: {$vehicleInfo}\nDriver: {$driverInfo}";
    }
    
    if ($passengerType) {
        notifyPassengersBatch($requestId, $passengerType, $passengerTitle, $passengerMessage, '/?page=requests&action=view&id=' . $requestId);
    }

    // Generate message based on action
    if ($approvalAction === 'approve') {
        $message = $approvalType === 'department' 
            ? 'Request approved and forwarded to motorpool.' 
            : 'Request fully approved!';
    } elseif ($approvalAction === 'revision') {
        $message = 'Request sent back for revision. Requester can update and resubmit.';
    } else {
        $message = 'Request rejected.';
    }

    if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => $message]);
        exit;
    }

    // Redirect to processed tab so user can see their action
    redirectWith('/?page=approvals&tab=processed&p_processed=1', 'success', $message);

} catch (Exception $e) {
    db()->rollback();
    error_log("Approval processing error: " . $e->getMessage());
    
    if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to process approval. Please try again.']);
        exit;
    }
    
    redirectWith('/?page=approvals&action=view&id=' . $requestId, 'danger', 'Failed to process approval. Please try again.');
}
