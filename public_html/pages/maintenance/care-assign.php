<?php
/**
 * Assign drivers responsible for vehicle care (Motorpool / Admin / All Father).
 */

require_once INCLUDES_PATH . '/vehicle_care.php';

if (!canManageCareAssignments()) {
    redirectWith('/?page=maintenance&action=schedule', 'danger', 'Administrator, Motorpool Head, or All Father access required.');
}

$pageTitle = 'Vehicle Care Assignments';
$errors = [];
$flashOk = null;

$vehicles = db()->fetchAll(
    "SELECT id, plate_number, make, model FROM vehicles WHERE deleted_at IS NULL ORDER BY plate_number"
);
$drivers = db()->fetchAll(
    "SELECT d.id, u.name, u.email
     FROM drivers d
     JOIN users u ON u.id = d.user_id
     WHERE d.deleted_at IS NULL AND u.deleted_at IS NULL AND u.status = 'active'
     ORDER BY u.name"
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $op = postSafe('op', '', 20);

    if ($op === 'assign') {
        $vehicleId = postInt('vehicle_id');
        $driverId = postInt('driver_id');
        if (!$vehicleId || !$driverId) {
            $errors[] = 'Select a vehicle and a driver.';
        } else {
            $exists = db()->fetch(
                "SELECT id, deleted_at FROM vehicle_care_assignments
                 WHERE vehicle_id = ? AND driver_id = ? LIMIT 1",
                [$vehicleId, $driverId]
            );
            if ($exists && !$exists->deleted_at) {
                $errors[] = 'That assignment already exists.';
            } elseif ($exists && $exists->deleted_at) {
                db()->update('vehicle_care_assignments', [
                    'deleted_at' => null,
                    'assigned_by' => userId(),
                    'updated_at' => date(DATETIME_FORMAT),
                ], 'id = ?', [$exists->id]);
                auditLog('care_assign_restore', 'vehicle_care_assignment', (int) $exists->id);
                $flashOk = 'Assignment restored.';
            } else {
                $id = db()->insert('vehicle_care_assignments', [
                    'vehicle_id' => $vehicleId,
                    'driver_id' => $driverId,
                    'assigned_by' => userId(),
                    'created_at' => date(DATETIME_FORMAT),
                ]);
                auditLog('care_assign', 'vehicle_care_assignment', (int) $id, null, [
                    'vehicle_id' => $vehicleId,
                    'driver_id' => $driverId,
                ]);
                $flashOk = 'Driver assigned to vehicle care.';
            }
        }
    } elseif ($op === 'remove') {
        $assignId = postInt('id');
        $row = db()->fetch(
            "SELECT * FROM vehicle_care_assignments WHERE id = ? AND deleted_at IS NULL",
            [$assignId]
        );
        if (!$row) {
            $errors[] = 'Assignment not found.';
        } else {
            db()->update('vehicle_care_assignments', [
                'deleted_at' => date(DATETIME_FORMAT),
                'updated_at' => date(DATETIME_FORMAT),
            ], 'id = ?', [$assignId]);
            auditLog('care_unassign', 'vehicle_care_assignment', $assignId, (array) $row, null);
            $flashOk = 'Assignment removed.';
        }
    }
}

$assignments = db()->fetchAll(
    "SELECT vca.*, v.plate_number, v.make, v.model, u.name AS driver_name, u.email AS driver_email
     FROM vehicle_care_assignments vca
     JOIN vehicles v ON v.id = vca.vehicle_id
     JOIN drivers d ON d.id = vca.driver_id
     JOIN users u ON u.id = d.user_id
     WHERE vca.deleted_at IS NULL
     ORDER BY v.plate_number, u.name"
);

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-clipboard-check me-2"></i>Vehicle Care Assignments</h4>
            <p class="text-muted mb-0">Drivers responsible for documents, PMS, and cleaning of each plate</p>
        </div>
        <a href="<?= APP_URL ?>/?page=maintenance&action=schedule" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to Schedule
        </a>
    </div>

    <?php foreach ($errors as $err): ?>
        <div class="alert alert-danger mb-3"><?= e($err) ?></div>
    <?php endforeach; ?>
    <?php if ($flashOk): ?>
        <div class="alert alert-success mb-3"><?= e($flashOk) ?></div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-body p-4">
            <h6 class="mb-3">Assign driver to vehicle</h6>
            <form method="POST" class="row g-3 align-items-end">
                <?= csrfField() ?>
                <input type="hidden" name="op" value="assign">
                <div class="col-md-4">
                    <label class="form-label">Vehicle</label>
                    <select name="vehicle_id" class="form-select" required>
                        <option value="">Select…</option>
                        <?php foreach ($vehicles as $v): ?>
                            <option value="<?= (int) $v->id ?>"><?= e($v->plate_number) ?> — <?= e($v->make . ' ' . $v->model) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Driver</label>
                    <select name="driver_id" class="form-select" required>
                        <option value="">Select…</option>
                        <?php foreach ($drivers as $d): ?>
                            <option value="<?= (int) $d->id ?>"><?= e($d->name) ?> (<?= e($d->email) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-person-plus me-1"></i>Assign</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-4">
            <?php if (empty($assignments)): ?>
                <p class="text-muted mb-0">No care assignments yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Vehicle</th>
                                <th>Driver</th>
                                <th class="text-end"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assignments as $a): ?>
                                <tr>
                                    <td>
                                        <strong><?= e($a->plate_number) ?></strong>
                                        <div><small class="text-muted"><?= e($a->make . ' ' . $a->model) ?></small></div>
                                    </td>
                                    <td>
                                        <?= e($a->driver_name) ?>
                                        <div><small class="text-muted"><?= e($a->driver_email) ?></small></div>
                                    </td>
                                    <td class="text-end">
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Remove this assignment?');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="op" value="remove">
                                            <input type="hidden" name="id" value="<?= (int) $a->id ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
