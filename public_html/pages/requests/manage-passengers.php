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
        'Passengers can no longer be changed — the vehicle has already been dispatched.');
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
    $rolledBack = false;

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
            $rolledBack = true;
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
                    // Requester cannot be added as passenger (already counted)
                    $isRequester = ((int) $locked->user_id === $uid);

                    if ($atCapacity) {
                        $errors[] = "This vehicle can only accommodate {$locked->passenger_capacity} passengers (including the requester) — no more passengers can be added.";
                    } elseif ($isDriverUser && $uid === $isDriverUser) {
                        $errors[] = 'The assigned driver cannot be added as a passenger.';
                    } elseif ($isRequester) {
                        $errors[] = 'The requester is already counted as a passenger.';
                    } else {
                        // Verify user exists and is active
                        $userCheck = db()->fetch("SELECT id, name FROM users WHERE id = ? AND deleted_at IS NULL AND status = 'active'", [$uid]);
                        if (!$userCheck) {
                            $errors[] = 'Selected employee not found or inactive.';
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

                            auditLog('passenger_added', 'request', $requestId, null, [
                                'user_id' => $uid,
                                'by' => userId()
                            ]);

                            db()->commit();
                            $rolledBack = true; // committed

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
            }
            if (!$rolledBack && !empty($errors)) {
                db()->rollback();
                $rolledBack = true;
            } elseif (!$rolledBack && empty($errors) && empty($success)) {
                // No success means validation failed without explicit rollback yet
                db()->rollback();
                $rolledBack = true;
            } elseif (!$rolledBack) {
                // Safety: if we somehow didn't commit/rollback
                db()->rollback();
                $rolledBack = true;
            }
        } elseif ($action === 'add_guest') {
            $guestName = trim((string) post('guest_name', ''));
            $guestName = Security::getInstance()->sanitizeString($guestName, 100);
            if ($guestName === '') {
                $errors[] = 'Please enter a guest name.';
            } else {
                $dup = db()->fetch(
                    "SELECT id FROM request_passengers WHERE request_id = ? AND guest_name = ?",
                    [$requestId, $guestName]
                );
                if ($dup) {
                    $errors[] = 'That guest is already on this request.';
                } else {
                    $passengerCount = countRequestPassengers($requestId);
                    $atCapacity = $locked->passenger_capacity > 0
                        && $passengerCount >= $locked->passenger_capacity;
                    if ($atCapacity) {
                        $errors[] = "This vehicle can only accommodate {$locked->passenger_capacity} passengers (including the requester) — no more passengers can be added.";
                    } else {
                        db()->insert('request_passengers', [
                            'request_id' => $requestId,
                            'guest_name' => $guestName,
                            'created_at' => date(DATETIME_FORMAT)
                        ]);
                        db()->update('requests', [
                            'passenger_count' => countRequestPassengers($requestId),
                            'updated_at' => date(DATETIME_FORMAT)
                        ], 'id = ?', [$requestId]);

                        auditLog('passenger_added', 'request', $requestId, null, [
                            'guest_name' => $guestName,
                            'by' => userId()
                        ]);

                        db()->commit();
                        $rolledBack = true;
                        $success = 'Guest passenger added successfully.';
                    }
                }
            }
            if (!$rolledBack) {
                if (!empty($errors)) {
                    db()->rollback();
                } else if (empty($success)) {
                    db()->rollback();
                } else {
                    db()->rollback();
                }
                $rolledBack = true;
            }
        } elseif ($action === 'remove') {
            $rowId = postInt('passenger_row_id');
            if (!$rowId) {
                $errors[] = 'Invalid passenger specified.';
            } else {
                $row = db()->fetch(
                    "SELECT id, user_id, guest_name FROM request_passengers WHERE id = ? AND request_id = ?",
                    [$rowId, $requestId]
                );
                if (!$row) {
                    $errors[] = 'Passenger not found on this request.';
                } else {
                    db()->query("DELETE FROM request_passengers WHERE id = ?", [$rowId]);
                    db()->update('requests', [
                        'passenger_count' => countRequestPassengers($requestId),
                        'updated_at' => date(DATETIME_FORMAT)
                    ], 'id = ?', [$requestId]);

                    auditLog('passenger_removed', 'request', $requestId, null, [
                        'passenger_row_id' => $rowId,
                        'user_id' => $row->user_id,
                        'guest_name' => $row->guest_name,
                        'by' => userId()
                    ]);

                    db()->commit();
                    $rolledBack = true;

                    if ($row->user_id) {
                        $success = 'Passenger removed successfully.';
                        try {
                            notify((int) $row->user_id, 'removed_from_request', 'Removed from Trip',
                                "You have been removed as a passenger from trip request #{$requestId} by the motorpool.",
                                '/?page=requests&action=view&id=' . $requestId, $requestId);
                        } catch (Throwable $e) {
                            error_log('manage-passengers remove notify: ' . $e->getMessage());
                        }
                    } else {
                        $success = 'Guest passenger removed successfully.';
                    }
                }
            }
            if (!$rolledBack) {
                if (!empty($errors)) {
                    db()->rollback();
                } else if (empty($success)) {
                    db()->rollback();
                } else {
                    db()->rollback();
                }
                $rolledBack = true;
            }
        } else {
            db()->rollback();
            $rolledBack = true;
            $errors[] = 'Invalid action.';
        }

        // Ensure transaction closed if still open
        if (db()->inTransaction()) {
            db()->rollback();
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollback();
        }
        error_log('manage-passengers: ' . $e->getMessage());
        $errors[] = 'An error occurred while updating passengers. Please try again.';
    }

    // Refresh request for display (status may have changed)
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

// Available employees (exclude requester — requester is always a passenger)
// Also exclude already-added passengers and driver user for cleaner dropdown
$allEmployees = getEmployees((int) $request->user_id);
$addedUserIds = [];
foreach ($passengers as $p) {
    if ($p->user_id) $addedUserIds[(int) $p->user_id] = true;
}
$driverUserId = 0;
if ($request->driver_id) {
    $dm = db()->fetch("SELECT user_id FROM drivers WHERE id = ? AND deleted_at IS NULL", [$request->driver_id]);
    if ($dm) $driverUserId = (int) $dm->user_id;
}
$employees = array_filter($allEmployees, function ($e) use ($addedUserIds, $driverUserId) {
    if (isset($addedUserIds[(int) $e->id])) return false;
    if ($driverUserId && (int) $e->id === $driverUserId) return false;
    return true;
});

$currentCount = countRequestPassengers($requestId);
$capacity = $request->passenger_capacity ? (int) $request->passenger_capacity : 0;
$atCapacity = $capacity > 0 && $currentCount >= $capacity;

$pageTitle = 'Manage Passengers — Request #' . $requestId;
require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-people me-2"></i>Manage Passengers</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>/?page=requests">Requests</a></li>
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>/?page=requests&action=view&id=<?= $requestId ?>">Request #<?= $requestId ?></a></li>
                <li class="breadcrumb-item active">Manage Passengers</li>
            </ol></nav>
        </div>
        <a href="<?= APP_URL ?>/?page=requests&action=view&id=<?= $requestId ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to Request
        </a>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= e($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <ul class="mb-0 ps-3"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Request summary -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="row g-3 align-items-center">
                <div class="col-md-4">
                    <small class="text-muted d-block">Request</small>
                    <strong>#<?= $requestId ?> — <?= e($request->destination) ?></strong><br>
                    <small class="text-muted"><?= formatDateTime($request->start_datetime) ?> → <?= formatDateTime($request->end_datetime) ?></small><br>
                    <span class="badge bg-<?= STATUS_LABELS[$request->status]['color'] ?? 'secondary' ?> mt-1"><?= e(STATUS_LABELS[$request->status]['label'] ?? ucfirst($request->status)) ?></span>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Requester</small>
                    <strong><?= e($request->requester_name) ?></strong>
                    <?php if ($request->vehicle_type_name): ?><br><small class="text-muted"><?= e($request->vehicle_type_name) ?> <?= $request->plate_number ? '— ' . e($request->plate_number . ' ' . $request->make . ' ' . $request->model) : '' ?></small><?php endif; ?>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Capacity</small>
                    <span class="fs-5 fw-bold <?= $atCapacity ? 'text-danger' : 'text-success' ?>"><?= $currentCount ?><?= $capacity ? ' / ' . $capacity : '' ?></span>
                    <small class="text-muted d-block"><?= $capacity ? ($atCapacity ? 'Vehicle is full' : ($capacity - $currentCount) . ' seat(s) left') : 'No vehicle assigned — no cap' ?></small>
                </div>
                <div class="col-md-2 text-md-end">
                    <small class="text-muted d-block">Status</small>
                    <?php if ($request->actual_dispatch_datetime): ?><span class="badge bg-danger">Dispatched — locked</span><?php else: ?><span class="badge bg-success">Editable</span><?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Current passengers -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-person-lines-fill me-2"></i>Current Passengers (<?= count($passengers) ?> companions + requester)</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Name</th><th>Type</th><th>Department</th><th class="text-end">Action</th></tr></thead>
                    <tbody>
                        <tr>
                            <td><i class="bi bi-person-fill me-1 text-primary"></i><strong><?= e($request->requester_name) ?></strong> <span class="badge bg-primary ms-1">Requester</span></td>
                            <td><span class="badge bg-primary">Requester</span></td>
                            <td class="text-muted">—</td>
                            <td class="text-end text-muted small">Cannot remove</td>
                        </tr>
                        <?php if (empty($passengers)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">No additional passengers.</td></tr>
                        <?php else: foreach ($passengers as $p): ?>
                            <tr>
                                <td>
                                    <?php if ($p->user_id): ?><i class="bi bi-person me-1"></i><?= e($p->user_name) ?><br><small class="text-muted"><?= e($p->user_email) ?></small>
                                    <?php else: ?><i class="bi bi-person-plus me-1 text-info"></i><?= e($p->guest_name) ?><?php endif; ?>
                                </td>
                                <td><?php if ($p->user_id): ?><span class="badge bg-secondary">System User</span><?php else: ?><span class="badge bg-info">Guest</span><?php endif; ?></td>
                                <td><?= $p->department_name ? e($p->department_name) : '<span class="text-muted">—</span>' ?></td>
                                <td class="text-end">
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Remove this passenger?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="remove">
                                        <input type="hidden" name="passenger_row_id" value="<?= (int) $p->passenger_row_id ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Add system user -->
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0"><i class="bi bi-person-plus me-2"></i>Add System User</h6></div>
                <div class="card-body">
                    <?php if ($atCapacity): ?>
                        <div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle me-2"></i>Vehicle is at capacity — remove a passenger before adding another.</div>
                    <?php elseif (empty($employees)): ?>
                        <div class="alert alert-info mb-0">No available employees to add (all already on this trip).</div>
                    <?php else: ?>
                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="add_user">
                            <div class="mb-3">
                                <label class="form-label">Select Employee <span class="text-danger">*</span></label>
                                <select class="form-select" name="user_id" required>
                                    <option value="">Choose employee…</option>
                                    <?php foreach ($employees as $emp): ?>
                                        <option value="<?= (int) $emp->id ?>"><?= e($emp->name) ?><?= !empty($emp->department_name) ? ' — ' . e($emp->department_name) : '' ?> (<?= e($emp->email) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Only active employees not already on this request are listed. The driver and requester are excluded.</small>
                            </div>
                            <button type="submit" class="btn btn-primary w-100" <?= $atCapacity ? 'disabled' : '' ?>><i class="bi bi-plus-circle me-1"></i>Add Passenger</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Add guest -->
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0"><i class="bi bi-person-badge me-2"></i>Add Guest Passenger</h6></div>
                <div class="card-body">
                    <?php if ($atCapacity): ?>
                        <div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle me-2"></i>Vehicle is at capacity — remove a passenger before adding another.</div>
                    <?php else: ?>
                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="add_guest">
                            <div class="mb-3">
                                <label class="form-label">Guest Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="guest_name" maxlength="100" placeholder="Enter guest full name" required>
                                <small class="text-muted">For passengers without a system account. Max 100 characters.</small>
                            </div>
                            <button type="submit" class="btn btn-info w-100" <?= $atCapacity ? 'disabled' : '' ?>><i class="bi bi-plus-circle me-1"></i>Add Guest</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
