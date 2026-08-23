<?php
/**
 * LOKA - Override Vehicle/Driver for Approved Request
 * 
 * Allows Motorpool Head to reassign vehicle/driver even after approval
 * Records all changes to assignment_history for audit trail
 */

requireRole(ROLE_MOTORPOOL);
requireCsrf();

$requestId = postInt('request_id');
$vehicleId = postInt('vehicle_id');
$driverId = postInt('driver_id');
$overrideReason = postSafe('override_reason', '', 1000);

if (!$requestId || !$vehicleId || !$driverId) {
    redirectWith('/?page=requests&action=view&id=' . $requestId, 'danger', 'Missing required fields.');
}

if (empty($overrideReason)) {
    redirectWith('/?page=requests&action=view&id=' . $requestId, 'danger', 'Override reason is required.');
}

try {
    db()->beginTransaction();
    
    $request = db()->fetch(
        "SELECT r.*, u.name as requester_name, u.email as requester_email
         FROM requests r
         JOIN users u ON r.user_id = u.id
         WHERE r.id = ? AND r.deleted_at IS NULL
         FOR UPDATE",
        [$requestId]
    );
    
    if (!$request) {
        throw new Exception('Request not found.');
    }
    
    if ($request->status !== STATUS_APPROVED && $request->status !== STATUS_MODIFIED) {
        throw new Exception('Can only override approved requests.');
    }
    
    $oldVehicleId = $request->vehicle_id;
    $oldDriverId = $request->driver_id;
    
    $newVehicle = db()->fetch(
        "SELECT v.*, vt.name as type_name FROM vehicles v JOIN vehicle_types vt ON v.vehicle_type_id = vt.id WHERE v.id = ?",
        [$vehicleId]
    );
    $newDriver = db()->fetch(
        "SELECT d.*, u.name as driver_name FROM drivers d JOIN users u ON d.user_id = u.id WHERE d.id = ?",
        [$driverId]
    );
    
    if (!$newVehicle || !$newDriver) {
        throw new Exception('Invalid vehicle or driver selected.');
    }
    
    $vehicleChanged = ($oldVehicleId != $vehicleId);
    $driverChanged = ($oldDriverId != $driverId);
    
    if (!$vehicleChanged && !$driverChanged) {
        throw new Exception('No changes detected. Same vehicle and driver already assigned.');
    }
    
    if ($oldVehicleId && $vehicleChanged) {
        db()->update('vehicles', ['status' => VEHICLE_AVAILABLE, 'updated_at' => date(DATETIME_FORMAT)], 'id = ?', [$oldVehicleId]);
    }
    if ($oldDriverId && $driverChanged) {
        db()->update('drivers', ['status' => DRIVER_AVAILABLE, 'updated_at' => date(DATETIME_FORMAT)], 'id = ?', [$oldDriverId]);
    }
    
    if ($vehicleChanged) {
        db()->update('vehicles', ['status' => VEHICLE_IN_USE, 'updated_at' => date(DATETIME_FORMAT)], 'id = ?', [$vehicleId]);
    }
    if ($driverChanged) {
        db()->update('drivers', ['status' => DRIVER_ON_TRIP, 'updated_at' => date(DATETIME_FORMAT)], 'id = ?', [$driverId]);
    }
    
    db()->update('requests', [
        'vehicle_id' => $vehicleId,
        'driver_id' => $driverId,
        'status' => STATUS_APPROVED,
        'updated_at' => date(DATETIME_FORMAT)
    ], 'id = ?', [$requestId]);
    
    db()->insert('assignment_history', [
        'request_id' => $requestId,
        'vehicle_id' => $vehicleId,
        'driver_id' => $driverId,
        'assigned_by' => userId(),
        'action' => 'overridden',
        'previous_vehicle_id' => $oldVehicleId,
        'previous_driver_id' => $oldDriverId,
        'reason' => $overrideReason,
        'created_at' => date(DATETIME_FORMAT)
    ]);
    
    db()->insert('approvals', [
        'request_id' => $requestId,
        'approver_id' => userId(),
        'approval_type' => 'motorpool',
        'status' => 'approved',
        'comments' => "VEHICLE/DRIVER OVERRIDE: " . $overrideReason,
        'created_at' => date(DATETIME_FORMAT)
    ]);
    
    auditLog('vehicle_driver_override', 'request', $requestId, [
        'old_vehicle_id' => $oldVehicleId,
        'old_driver_id' => $oldDriverId,
        'new_vehicle_id' => $vehicleId,
        'new_driver_id' => $driverId
    ], [
        'override_reason' => $overrideReason,
        'old_vehicle_id' => $oldVehicleId,
        'old_driver_id' => $oldDriverId,
        'new_vehicle_id' => $vehicleId,
        'new_driver_id' => $driverId
    ]);
    
    db()->commit();
    
    $vehicleInfo = "{$newVehicle->plate_number} - {$newVehicle->make} {$newVehicle->model}";
    $driverInfo = $newDriver->driver_name;
    
    notify(
        $request->user_id,
        'vehicle_driver_override',
        'Vehicle/Driver Assignment Changed',
        "Your approved trip to {$request->destination} has been reassigned.\n\nNew Vehicle: {$vehicleInfo}\nNew Driver: {$driverInfo}\n\nReason: {$overrideReason}",
        '/?page=requests&action=view&id=' . $requestId
    );
    
    notify(
        $newDriver->user_id,
        'driver_assigned',
        'You Have Been Assigned as Driver (Override)',
        "You have been assigned as the driver for a trip to {$request->destination}.\n\nDeparture: " . formatDateTime($request->start_datetime) . "\nVehicle: {$vehicleInfo}\n\nThis is an override assignment.",
        '/?page=requests&action=view&id=' . $requestId
    );
    
    if ($oldDriverId && $driverChanged) {
        $oldDriver = db()->fetch("SELECT user_id FROM drivers WHERE id = ?", [$oldDriverId]);
        if ($oldDriver) {
            notify(
                $oldDriver->user_id,
                'driver_unassigned',
                'Driver Assignment Removed',
                "You have been removed from the trip to {$request->destination} on " . formatDate($request->start_datetime) . ".\n\nReason: {$overrideReason}",
                '/?page=requests&action=view&id=' . $requestId
            );
        }
    }
    
    $deptApproval = db()->fetch(
        "SELECT a.approver_id FROM approvals a WHERE a.request_id = ? AND a.approval_type = 'department' AND a.status = 'approved' ORDER BY a.created_at DESC LIMIT 1",
        [$requestId]
    );
    
    if ($deptApproval) {
        $oldVehicleInfo = '';
        $oldDriverInfo = '';
        
        if ($oldVehicleId) {
            $oldVehicle = db()->fetch(
                "SELECT v.plate_number, v.make, v.model FROM vehicles v WHERE v.id = ?",
                [$oldVehicleId]
            );
            if ($oldVehicle) {
                $oldVehicleInfo = "{$oldVehicle->plate_number} - {$oldVehicle->make} {$oldVehicle->model}";
            }
        }
        
        if ($oldDriverId) {
            $oldDriverRec = db()->fetch(
                "SELECT u.name FROM drivers d JOIN users u ON d.user_id = u.id WHERE d.id = ?",
                [$oldDriverId]
            );
            if ($oldDriverRec) {
                $oldDriverInfo = $oldDriverRec->name;
            }
        }
        
        $changesMsg = '';
        if ($vehicleChanged) {
            $changesMsg .= "\nOld Vehicle: {$oldVehicleInfo}";
            $changesMsg .= "\nNew Vehicle: {$vehicleInfo}";
        }
        if ($driverChanged) {
            $changesMsg .= "\nOld Driver: {$oldDriverInfo}";
            $changesMsg .= "\nNew Driver: {$driverInfo}";
        }
        
        notify(
            $deptApproval->approver_id,
            'vehicle_driver_override',
            'Vehicle/Driver Override Notification',
            "A request you approved for {$request->requester_name}'s trip to {$request->destination} has had its vehicle/driver assignment overridden by Motorpool.\n{$changesMsg}\n\nReason: {$overrideReason}",
            '/?page=requests&action=view&id=' . $requestId
        );
    }
    
    $passengers = db()->fetchAll(
        "SELECT user_id FROM request_passengers WHERE request_id = ? AND user_id IS NOT NULL",
        [$requestId]
    );
    
    foreach ($passengers as $passenger) {
        notify(
            $passenger->user_id,
            'trip_vehicle_changed',
            'Trip Vehicle/Driver Changed',
            "Your trip to {$request->destination} has a new vehicle/driver assignment.\n\nNew Vehicle: {$vehicleInfo}\nNew Driver: {$driverInfo}",
            '/?page=requests&action=view&id=' . $requestId
        );
    }
    
    redirectWith('/?page=requests&action=view&id=' . $requestId, 'success', 'Vehicle and driver have been reassigned successfully.');
    
} catch (Exception $e) {
    db()->rollback();
    error_log("Override error: " . $e->getMessage());
    redirectWith('/?page=requests&action=view&id=' . $requestId, 'danger', 'Failed to override: ' . $e->getMessage());
}
