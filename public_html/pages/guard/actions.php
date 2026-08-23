<?php
/**
 * LOKA - Guard Actions Handler
 * 
 * Handles recording of dispatch and arrival times
 */

requireRole(ROLE_GUARD);
requireCsrf();

$action = get('action');
$requestId = postInt('request_id');

if (!$requestId) {
    redirectWith('/?page=guard', 'danger', 'Invalid request.');
}

// Verify the request exists and is approved
$request = db()->fetch(
    "SELECT r.*, u.name as requester_name, u.email as requester_email
     FROM requests r
     JOIN users u ON r.user_id = u.id
     WHERE r.id = ? AND r.status = 'approved' AND r.deleted_at IS NULL",
    [$requestId]
);

if (!$request) {
    redirectWith('/?page=guard', 'danger', 'Request not found or not approved.');
}

$now = date(DATETIME_FORMAT);

switch ($action) {
    case 'record_dispatch':
        // Check if already dispatched
        if ($request->actual_dispatch_datetime) {
            redirectWith('/?page=guard', 'warning', 'This vehicle has already been dispatched.');
        }

        $dispatchTime = post('dispatch_time');
        $guardNotes = postSafe('guard_notes', '', 500);

        // Travel documents
        $hasTravelOrder = post('has_travel_order') ? 1 : 0;
        $hasObSlip = post('has_official_business_slip') ? 1 : 0;
        $travelOrderNumber = null;
        $obSlipNumber = null;

        if ($hasTravelOrder) {
            $travelOrderNumber = postSafe('travel_order_number', '', 50);
            if (empty($travelOrderNumber)) {
                redirectWith('/?page=guard', 'danger', 'Travel Order number is required when Travel Order checkbox is checked.');
            }
        }

        if ($hasObSlip) {
            $obSlipNumber = postSafe('ob_slip_number', '', 50);
            if (empty($obSlipNumber)) {
                redirectWith('/?page=guard', 'danger', 'OB Slip number is required when OB Slip checkbox is checked.');
            }
        }

        if (!$dispatchTime) {
            redirectWith('/?page=guard', 'danger', 'Dispatch time is required.');
        }

        // Format the datetime
        $formattedDispatchTime = date('Y-m-d H:i:s', strtotime($dispatchTime));

        // Update request
        db()->update('requests', [
            'actual_dispatch_datetime' => $formattedDispatchTime,
            'dispatch_guard_id' => userId(),
            'guard_notes' => $guardNotes ?: null,
            'has_travel_order' => $hasTravelOrder,
            'has_official_business_slip' => $hasObSlip,
            'travel_order_number' => $travelOrderNumber,
            'ob_slip_number' => $obSlipNumber,
            'updated_at' => $now
        ], 'id = ?', [$requestId]);
        
        // Audit log
        auditLog(
            'vehicle_dispatched',
            'request',
            $requestId,
            null,
            [
                'dispatch_time' => $formattedDispatchTime,
                'guard_id' => userId(),
                'guard_notes' => $guardNotes
            ]
        );
        
        // Notify requester
        notify(
            $request->user_id,
            'vehicle_dispatched',
            'Vehicle Dispatched',
            "Your vehicle for request #{$requestId} to {$request->destination} has departed at " . formatDateTime($formattedDispatchTime) . ".",
            '/?page=requests&action=view&id=' . $requestId,
            $requestId
        );
        
        // Notify driver if assigned
        if ($request->driver_id) {
            notifyDriver(
                $request->driver_id,
                'trip_started',
                'Trip Started',
                "Trip for request #{$requestId} to {$request->destination} has officially started. Dispatch time: " . formatDateTime($formattedDispatchTime),
                '/?page=requests&action=view&id=' . $requestId
            );
        }
        
        redirectWith('/?page=guard', 'success', "Dispatch recorded for request #{$requestId}.");
        break;
        
    case 'record_arrival':
        // Check if dispatched first
        if (!$request->actual_dispatch_datetime) {
            redirectWith('/?page=guard', 'danger', 'Vehicle must be dispatched before recording arrival.');
        }

        // Check if already arrived
        if ($request->actual_arrival_datetime) {
            redirectWith('/?page=guard', 'warning', 'This vehicle has already returned.');
        }

        $arrivalTime = post('arrival_time');
        $mileageEnd = postInt('mileage_end') ?: null; // Optional ending mileage
        $guardNotes = postSafe('guard_notes', '', 500);

        if (!$arrivalTime) {
            redirectWith('/?page=guard', 'danger', 'Arrival time is required.');
        }

        // Format the datetime
        $formattedArrivalTime = date('Y-m-d H:i:s', strtotime($arrivalTime));

        // Validate arrival is after dispatch
        if (strtotime($formattedArrivalTime) <= strtotime($request->actual_dispatch_datetime)) {
            redirectWith('/?page=guard', 'danger', 'Arrival time must be after dispatch time.');
        }

        // Validate mileage_end if provided (must be >= mileage_start if set)
        if ($mileageEnd !== null && $request->mileage_start !== null && $mileageEnd < $request->mileage_start) {
            redirectWith('/?page=guard', 'danger', 'Ending mileage cannot be less than starting mileage.');
        }

        // Calculate actual mileage if both values exist
        $mileageActual = null;
        if ($mileageEnd !== null && $request->mileage_start !== null) {
            $mileageActual = $mileageEnd - $request->mileage_start;
        }

        // Update request - mark as completed
        $updateData = [
            'actual_arrival_datetime' => $formattedArrivalTime,
            'arrival_guard_id' => userId(),
            'status' => STATUS_COMPLETED,
            'guard_notes' => $guardNotes ? ($request->guard_notes . "\n\n[Arrival] " . $guardNotes) : $request->guard_notes,
            'updated_at' => $now
        ];

        // Update mileage fields if provided
        if ($mileageEnd !== null) {
            $updateData['mileage_end'] = $mileageEnd;
        }
        if ($mileageActual !== null) {
            $updateData['mileage_actual'] = $mileageActual;
        }

        db()->update('requests', $updateData, 'id = ?', [$requestId]);

        // Update vehicle and driver status back to available
        if ($request->vehicle_id) {
            $vehicleUpdateData = ['status' => 'available', 'updated_at' => $now];
            // Update vehicle mileage if mileage_end was provided
            if ($mileageEnd !== null) {
                $vehicleUpdateData['mileage'] = $mileageEnd;
            }
            db()->update('vehicles', $vehicleUpdateData, 'id = ?', [$request->vehicle_id]);
        }
        if ($request->driver_id) {
            db()->update('drivers', ['status' => 'available', 'updated_at' => $now], 'id = ?', [$request->driver_id]);
        }
        
        // Audit log
        auditLog(
            'vehicle_arrived',
            'request',
            $requestId,
            [
                'dispatch_time' => $request->actual_dispatch_datetime,
                'old_status' => $request->status
            ],
            [
                'arrival_time' => $formattedArrivalTime,
                'guard_id' => userId(),
                'guard_notes' => $guardNotes,
                'new_status' => STATUS_COMPLETED
            ]
        );
        
        // Notify requester
        notify(
            $request->user_id,
            'vehicle_arrived',
            'Vehicle Returned - Trip Completed',
            "Your vehicle for request #{$requestId} to {$request->destination} has returned at " . formatDateTime($formattedArrivalTime) . ". Trip completed!",
            '/?page=requests&action=view&id=' . $requestId,
            $requestId
        );
        
        // Notify passengers
        notifyPassengersBatch(
            $requestId,
            'trip_completed',
            'Trip Completed',
            "The trip to {$request->destination} has been completed. Vehicle returned at " . formatDateTime($formattedArrivalTime) . ".",
            '/?page=requests&action=view&id=' . $requestId
        );
        
        // Notify driver
        if ($request->driver_id) {
            notifyDriver(
                $request->driver_id,
                'trip_completed',
                'Trip Completed',
                "Trip for request #{$requestId} to {$request->destination} has been completed. Arrival time: " . formatDateTime($formattedArrivalTime),
                '/?page=requests&action=view&id=' . $requestId
            );
        }
        
        redirectWith(
            '/?page=trip-tickets&action=create_form&request_id=' . $requestId,
            'success',
            "Arrival recorded for request #{$requestId}. Trip marked as completed. Please create a trip ticket to document details."
        );
        break;
        
    default:
        redirectWith('/?page=guard', 'danger', 'Invalid action.');
}
