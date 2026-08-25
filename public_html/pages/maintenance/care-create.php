<?php
/**
 * Propose / create a vehicle care schedule item.
 */

require_once INCLUDES_PATH . '/vehicle_care.php';

if (!canProposeCareSchedule()) {
    redirectWith('/?page=dashboard', 'danger', 'You cannot create care schedule items.');
}

$pageTitle = 'New Care Schedule';
$errors = [];

$preVehicle = getInt('vehicle_id') ?: null;
$preType = getSafe('type', '', 32);
if ($preType !== '' && !isset(CARE_TYPES[$preType])) {
    $preType = '';
}

if (canApproveCareSchedules()) {
    $vehicles = db()->fetchAll(
        "SELECT id, plate_number, make, model FROM vehicles WHERE deleted_at IS NULL ORDER BY plate_number"
    );
} else {
    $ids = careVehicleIdsForDriver();
    if ($ids === []) {
        redirectWith('/?page=maintenance&action=schedule', 'warning', 'No vehicles assigned to you for care.');
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $vehicles = db()->fetchAll(
        "SELECT id, plate_number, make, model FROM vehicles
         WHERE deleted_at IS NULL AND id IN ({$ph}) ORDER BY plate_number",
        $ids
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $vehicleId = postInt('vehicle_id');
    $careType = postSafe('care_type', '', 32);
    $title = postSafe('title', '', 255);
    $notes = postSafe('notes', '', 2000);
    $dueDate = postSafe('due_date', '', 20);
    $intervalDays = post('interval_days') !== '' ? postInt('interval_days') : null;
    $intervalKm = post('interval_km') !== '' ? postInt('interval_km') : null;

    if (!$vehicleId || !canViewCareVehicle($vehicleId)) {
        $errors[] = 'Invalid vehicle.';
    }
    if (!isset(CARE_TYPES[$careType])) {
        $errors[] = 'Invalid care type.';
    }
    if ($title === '') {
        $errors[] = 'Title is required.';
    }
    if ($dueDate === '' || !strtotime($dueDate)) {
        $errors[] = 'Valid due date is required.';
    }
    if ($careType === CARE_TYPE_OTHER && trim($notes) === '') {
        $errors[] = 'Notes are required for Other type.';
    }

    $defaults = careDefaultIntervals($careType);
    if ($intervalDays === null) {
        $intervalDays = $defaults['interval_days'];
    }
    if ($intervalKm === null) {
        $intervalKm = $defaults['interval_km'];
    }
    if (!(CARE_TYPES[$careType]['recurring'] ?? false)) {
        $intervalDays = null;
        $intervalKm = null;
    }

    if (empty($errors)) {
        $autoApprove = canApproveCareSchedules();
        $status = $autoApprove ? CARE_STATUS_SCHEDULED : CARE_STATUS_PENDING;
        $id = db()->insert('vehicle_care_schedules', [
            'vehicle_id' => $vehicleId,
            'care_type' => $careType,
            'title' => $title,
            'notes' => $notes !== '' ? $notes : null,
            'due_date' => $dueDate,
            'status' => $status,
            'proposed_by' => userId(),
            'approved_by' => $autoApprove ? userId() : null,
            'approved_at' => $autoApprove ? date(DATETIME_FORMAT) : null,
            'interval_days' => $intervalDays,
            'interval_km' => $intervalKm,
            'created_at' => date(DATETIME_FORMAT),
        ]);

        $plate = db()->fetch("SELECT plate_number FROM vehicles WHERE id = ?", [$vehicleId]);
        $link = '/?page=maintenance&action=care-edit&id=' . $id;
        $label = CARE_TYPES[$careType]['label'] ?? $careType;
        if ($autoApprove) {
            notifyCareStakeholders(
                $vehicleId,
                'care_schedule_scheduled',
                'Care item scheduled',
                "{$label} for {$plate->plate_number}: {$title} due " . formatDate($dueDate) . ".",
                $link
            );
        } else {
            notifyCareStakeholders(
                $vehicleId,
                'care_schedule_proposed',
                'Care item proposed',
                (currentUser()->name ?? 'A driver') . " proposed {$label} for {$plate->plate_number}: {$title} due " . formatDate($dueDate) . ". Awaiting Motorpool approval.",
                $link,
                userId()
            );
        }

        auditLog('care_schedule_create', 'vehicle_care_schedule', (int) $id, null, [
            'status' => $status,
            'care_type' => $careType,
            'vehicle_id' => $vehicleId,
        ]);

        redirectWith(
            '/?page=maintenance&action=schedule',
            'success',
            $autoApprove ? 'Care item scheduled.' : 'Care item submitted for approval.'
        );
    }
}

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container py-4">
    <h4 class="mb-1"><i class="bi bi-calendar-plus me-2"></i>New Care Schedule</h4>
    <p class="text-muted mb-4">PMS, registration, cleaning, or other care for an assigned vehicle</p>

    <?php foreach ($errors as $err): ?>
        <div class="alert alert-danger mb-3"><?= e($err) ?></div>
    <?php endforeach; ?>

    <div class="card">
        <div class="card-body p-4">
            <form method="POST">
                <?= csrfField() ?>
                <div class="mb-3">
                    <label class="form-label">Vehicle</label>
                    <select name="vehicle_id" class="form-select" required>
                        <option value="">Select…</option>
                        <?php foreach ($vehicles as $v): ?>
                            <option value="<?= (int) $v->id ?>" <?= (int) $preVehicle === (int) $v->id ? 'selected' : '' ?>>
                                <?= e($v->plate_number) ?> — <?= e($v->make . ' ' . $v->model) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Type</label>
                    <select name="care_type" class="form-select" required>
                        <?php foreach (CARE_TYPES as $key => $info): ?>
                            <option value="<?= e($key) ?>" <?= $preType === $key ? 'selected' : '' ?>><?= e($info['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Title</label>
                    <input type="text" name="title" class="form-control" maxlength="255" required
                           value="<?= e(post('title', '')) ?>" placeholder="e.g. Q3 PMS / LTO registration">
                </div>
                <div class="mb-3">
                    <label class="form-label">Due date</label>
                    <input type="date" name="due_date" class="form-control" required value="<?= e(post('due_date', '')) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="3" maxlength="2000"><?= e(post('notes', '')) ?></textarea>
                </div>
                <?php if (canApproveCareSchedules()): ?>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Interval days (optional)</label>
                        <input type="number" name="interval_days" class="form-control" min="1" value="<?= e(post('interval_days', '')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Interval km (optional)</label>
                        <input type="number" name="interval_km" class="form-control" min="1" value="<?= e(post('interval_km', '')) ?>">
                    </div>
                </div>
                <?php endif; ?>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <?= canApproveCareSchedules() ? 'Save & schedule' : 'Submit for approval' ?>
                    </button>
                    <a href="<?= APP_URL ?>/?page=maintenance&action=schedule" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
