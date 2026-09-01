<?php
/**
 * LOKA - Manage Passengers (Motorpool Head / Admin / All Father)
 *
 * Adds or removes passengers on requests that are already APPROVED or still
 * ON ROUTING (pending / pending_motorpool), as long as the guard has not
 * yet dispatched the vehicle (actual_dispatch_datetime IS NULL).
 */

requireAuth();

$requestId = (int) get('id');
$errors = [];
$success = '';

// Authorized roles
$canManage = false;
$request = null;

if ($requestId) {
    $request = db()->fetch(
        "SELECT r.*,
                v.plate_number, v.make, v.model,
                vt.name AS vehicle_type_name, vt.passenger_capacity,
                u.name AS requester_name
         FROM requests r
         LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
         LEFT JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
         JOIN users u ON r.user_id = u.id
         WHERE r.id = ? AND r.deleted_at IS NULL",
        [$requestId]
    );
}

if (!$request) {
    redirectWith('/?page=requests', 'danger', 'Request not found.');
}

// Status + dispatch gate
$allowedStatuses = [STATUS_PENDING, STATUS_PENDING_MOTORPOOL, STATUS_APPROVED];
if (!in_array($request->status, $allowedStatuses, true)) {
    redirectWith('/?page=requests&action=view&id=' . $requestId, 'danger',
        'Passengers can only be managed while the request is approved or on routing.');
}
if ($request->actual_dispatch_datetime !== null) {
    redirectWith('/?page=requests&action=view&id=' . $requestId, 'danger',
        'Passengers can no longer be changed â€” the vehicle has already been dispatched.');
}

// Authorization
if (isAdmin() || isAllFather()) {
    $canManage = true;
} elseif (isMotorpool()) {
    if ($request->motorpool_head_id && $request->motorpool_head_id == userId()) {
        $canManage = true;
    } elseif (!$request->motorpool_head_id) {
        $canManage = true;
    }
}

if (!$canManage) {
    redirectWith('/?page=requests&action=view&id=' . $requestId, 'danger',
        'You do not have permission to manage passengers on this request.');
}

// =====================================================================
// Handle POST (add user / add guest / remove passenger)
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $action = post('action', '');

    try {
        db()->beginTransaction();

        // Re-fetch request with FOR UPDATE lock
        $locked = db()->fetch(
            "SELECT r.*, vt.passenger_capacity
             FROM requests r
             LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
             LEFT JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
             WHERE r.id = ? AND r.deleted_at IS NULL
             FOR UPDATE",
            [$requestId]
        );

        if (!$locked || !in_array($locked->status, $allowedStatuses, true) || $locked->actual_dispatch_datetime !== null) {

            db()->rollback();
            $errors[] = 'This request can no longer be modified.';
        } elseif ($action === 'add_user') {
            $uid = postInt('user_id');
            if (!$uid) {
                $errors[] = 'Please select an employee.';
            } else {
                $dup = db()->fetch(
                    "SELECT id FROM request_passengers WHERE request_id = ? AND user_id = ?",
                    [$requestId, $uid]
                );
                if ($dup) {
                    $errors[] = 'That employee is already on this request.';
                } else {
                    $passengerCount = countRequestPassengers($requestId);
                    $atCapacity = $locked->passenger_capacity > 0
                        && $passengerCount >= $locked->passenger_capacity;
                    $isDriverUser = 0;
                    if ($locked->driver_id) {
                        $driverMatch = db()->fetch(
                            "SELECT d.user_id FROM drivers d WHERE d.id = ? AND d.deleted_at IS NULL",
                            [$locked->driver_id]
                        );
                        $isDriverUser = $driverMatch ? (int) $driverMatch->user_id : 0;
                    }

                    if ($atCapacity) {
                        $errors[] = "This vehicle can only accommodate {$locked->passenger_capacity} passengers (including the requester) â€” no more passengers can be added.";
                    } elseif ($isDriverUser && $uid === $isDriverUser) {
                         $errors[] = 'The assigned driver cannot be added as a passenger.';
                    } else {
                        db()->insert('request_passengers', [
                            'request_id' => $requestId,
                            'user_id' => $uid,
                            'created_at' => date(DATETIME_FORMAT)
                        ]);
                        db()->update('requests', [
                            'passenger_count' => countRequestPassengers($requestId),
                            'updated_at' => date(DATETIME_FORMAT)
                        ], 'id = ?', [$requestId]);

                        auditLog('passenger_added', 'request', $requestId,null, [
                            'user_id' => $uid,
                            'by' => userId()
                        ]);

                        db()->commit();

                        $success = 'Passenger added successfully.';
                        try {
                            notify($uid, 'added_to_request', 'Added to Trip',
                                "You have been added as a passenger to trip request #{$requestId} "
                                . "by the motorpool. The trip is " . ($locked->status === STATUS_APPROVED ? 'approved' : 'on routing') . '.',
                                '/?page=requests&action=view&id=' . $requestId, $requestId);
                        } catch (Throwable $e) {
                            error_log('manage-passengers add notify: ' . $e->getMessage());
                        }
                    }
                }
            }
/* __MP_POST2__ */

// Load current passengers
$passengers = db()->fetchAll(
    "SELECT rp.id AS passenger_row_id, rp.user_id, rp.guest_name,
            u.name AS user_name, u.email AS user_email, u.department_id,
            d.name AS department_name
     FROM request_passengers rp
     LEFT JOIN users u ON rp.user_id = u.id AND u.deleted_at IS NULL
     LEFT JOIN departments d ON u.department_id = d.id
     WHERE rp.request_id = ?
     ORDER BY u.name, rp.guest_name",
    [$requestId]
);

// Available employees (exclude requester â€” requester is always a passenger)
$employees = getEmployees((int) $request->user_id);

require_once INCLUDES_PATH . '/header.php';
?>
/* __MP_UI__ */
<?php require_once INCLUDES_PATH . '/footer.php'; ?>
