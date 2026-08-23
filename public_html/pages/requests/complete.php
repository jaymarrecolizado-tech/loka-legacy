<?php
/**
 * LOKA - Complete Trip / Mark Request as Completed (Hardened Version)
 * Releases vehicle and driver back to available status
 */

requireRole(ROLE_MOTORPOOL);
requireCsrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/?page=requests');
}

$requestId = postInt('request_id');
$notes = postSafe('completion_notes', '', 1000);
$endingMileage = post('ending_mileage') ? (int)post('ending_mileage') : null;

// Get request with vehicle and driver info - FOR UPDATE locking
$request = db()->fetch(
    "SELECT r.*, v.plate_number, d.id as driver_id, u.name as driver_name
     FROM requests r
     LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
     LEFT JOIN drivers d ON r.driver_id = d.id AND d.deleted_at IS NULL
     LEFT JOIN users u ON d.user_id = u.id
     WHERE r.id = ? AND r.deleted_at IS NULL
     FOR UPDATE",
    [$requestId]
);


if (!$request) {
    redirectWith('/?page=requests', 'danger', 'Request not found.');
}

// Can only complete approved requests
if ($request->status !== STATUS_APPROVED) {
    redirectWith('/?page=requests&action=view&id=' . $requestId, 'danger', 'Only approved requests can be marked as completed.');
}

// Authorization check: Verify motorpool head has authority over this request
$canComplete = false;
if (isAdmin()) {
    $canComplete = true;
} elseif ($request->motorpool_head_id == userId()) {
    $canComplete = true;
} elseif (!$request->motorpool_head_id && isMotorpool()) {
    $canComplete = true;
}

if (!$canComplete) {
    redirectWith('/?page=requests', 'danger', 'You do not have permission to complete this request.');
}

try {
    db()->beginTransaction();
    
    // Update request status
    db()->update('requests', [
        'status' => STATUS_COMPLETED,
        'notes' => $request->notes . ($notes ? "\n\nCompletion Notes: " . $notes : ''),
        'updated_at' => date(DATETIME_FORMAT)
    ], 'id = ?', [$requestId]);
    
    // Release vehicle back to available
    if ($request->vehicle_id) {
        $vehicleUpdate = ['status' => VEHICLE_AVAILABLE, 'updated_at' => date(DATETIME_FORMAT)];
        if ($endingMileage) {
            $vehicleUpdate['mileage'] = $endingMileage;
        }
        db()->update('vehicles', $vehicleUpdate, 'id = ?', [$request->vehicle_id]);
    }
    
    // Release driver back to available
    if ($request->driver_id) {
        db()->update('drivers', [
            'status' => DRIVER_AVAILABLE,
            'updated_at' => date(DATETIME_FORMAT)
        ], 'id = ?', [$request->driver_id]);
    }
    
    // =====================================================
    // PREPARE NOTIFICATIONS FOR DEFERRED SENDING
    // =====================================================
    
    $completionMessage = 'Your trip has been marked as completed. Vehicle and driver have been released.';
    $passengerMessage = 'A trip you were part of has been marked as completed.';
    $driverMessage = 'A trip you were assigned to drive has been marked as completed. Vehicle and driver have been released.';
    $link = '/?page=requests&action=view&id=' . $requestId;
    
    // Prepare deferred notifications
    $deferredNotifications = [];
    
    // Requester notification
    $deferredNotifications[] = [
        'user_id' => $request->user_id,
        'type' => 'trip_completed',
        'title' => 'Trip Completed',
        'message' => $completionMessage,
        'link' => $link
    ];
    
    // Prepare passenger notification data
    $passengerNotificationData = [
        'request_id' => $requestId,
        'type' => 'trip_completed',
        'title' => 'Trip Completed',
        'message' => $passengerMessage,
        'link' => $link
    ];
    
    // Prepare driver notification
    $driverNotificationData = null;
    
    if ($request->driver_id) {
        $driverNotificationData = [
            'driver_id' => $request->driver_id,
            'type' => 'trip_completed',
            'title' => 'Trip Completed',
            'message' => $driverMessage,
            'link' => $link
        ];
    }
    
    // Audit log
    auditLog('request_completed', 'request', $requestId, 
        ['status' => STATUS_APPROVED], 
        ['status' => STATUS_COMPLETED, 'notes' => $notes]
    );
    
    db()->commit();
    
    // =====================================================
    // SEND NOTIFICATIONS AFTER SUCCESSFUL COMMIT
    // =====================================================
    
    // Send deferred notifications
    foreach ($deferredNotifications as $notif) {
        notify($notif['user_id'], $notif['type'], $notif['title'], $notif['message'], $notif['link']);
    }
    
    // Notify passengers
    notifyPassengers(
        $passengerNotificationData['request_id'],
        $passengerNotificationData['type'],
        $passengerNotificationData['title'],
        $passengerNotificationData['message'],
        $passengerNotificationData['link']
    );
    
    // Notify driver
    if ($driverNotificationData) {
        notifyDriver(
            $driverNotificationData['driver_id'],
            $driverNotificationData['type'],
            $driverNotificationData['title'],
            $driverNotificationData['message'],
            $driverNotificationData['link']
        );
    }
    
    redirectWith('/?page=requests&action=view&id=' . $requestId, 'success', 'Trip marked as completed. Vehicle and driver released.');
    
} catch (Exception $e) {
    db()->rollback();
    error_log("Trip completion error: " . $e->getMessage());
    redirectWith('/?page=requests&action=view&id=' . $requestId, 'danger', 'Failed to complete trip. Please try again.');
}
