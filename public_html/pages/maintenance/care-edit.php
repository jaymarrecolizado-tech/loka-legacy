<?php
/**
 * View / approve / complete / cancel a vehicle care schedule item.
 */

require_once INCLUDES_PATH . '/vehicle_care.php';

$id = getInt('id');
$item = db()->fetch(
    "SELECT vcs.*, v.plate_number, v.make, v.model, v.mileage
     FROM vehicle_care_schedules vcs
     JOIN vehicles v ON v.id = vcs.vehicle_id
     WHERE vcs.id = ? AND vcs.deleted_at IS NULL",
    [$id]
);

if (!$item || !canViewCareVehicle((int) $item->vehicle_id)) {
    redirectWith('/?page=maintenance&action=schedule', 'danger', 'Care item not found.');
}

$pageTitle = 'Care #' . $id;
$errors = [];
$canApprove = canApproveCareSchedules();
$canComplete = $canApprove || (currentDriverId() && in_array((int) $item->vehicle_id, careVehicleIdsForDriver(), true));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $op = postSafe('op', '', 20);

    if ($op === 'approve' && $canApprove && $item->status === CARE_STATUS_PENDING) {
        // Plan #40 — the real screen and the AI assistant share ONE implementation
        // (includes/maintenance_service.php) so the rules cannot drift apart.
        require_once INCLUDES_PATH . '/maintenance_service.php';
        $res = maintenanceServiceApproveCare($item, (int) userId());
        if (!$res['ok']) {
            $errors[] = $res['error'];
        } else {
            redirectWith('/?page=maintenance&action=care-edit&id=' . $id, 'success', 'Approved and scheduled.');
        }
    }

    if ($op === 'save' && $canApprove) {
        $title = postSafe('title', '', 255);
        $notes = postSafe('notes', '', 2000);
        $dueDate = postSafe('due_date', '', 20);
        $careType = postSafe('care_type', '', 32);
        if ($title === '' || !strtotime($dueDate) || !isset(CARE_TYPES[$careType])) {
            $errors[] = 'Title, type, and due date are required.';
        } else {
            db()->update('vehicle_care_schedules', [
                'title' => $title,
                'notes' => $notes !== '' ? $notes : null,
                'due_date' => $dueDate,
                'care_type' => $careType,
                'updated_at' => date(DATETIME_FORMAT),
            ], 'id = ?', [$id]);
            auditLog('care_schedule_edit', 'vehicle_care_schedule', $id);
            redirectWith('/?page=maintenance&action=care-edit&id=' . $id, 'success', 'Updated.');
        }
    }

    if ($op === 'complete' && $canComplete && in_array($item->status, [CARE_STATUS_PENDING, CARE_STATUS_SCHEDULED], true)) {
        $mileage = post('completed_mileage') !== '' ? postInt('completed_mileage') : null;
        require_once INCLUDES_PATH . '/repair_history.php';
        $careCostItems = repairHistoryNormalizeItems(repairHistoryItemsFromPost())[0];

        // Plan #40 — shared implementation with the AI assistant so there is one
        // set of rules (see includes/maintenance_service.php).
        require_once INCLUDES_PATH . '/maintenance_service.php';
        $res = maintenanceServiceCompleteCare($item, $mileage, $careCostItems, (int) userId());
        if (!$res['ok']) {
            $errors[] = $res['error'];
        } else {
            redirectWith('/?page=maintenance&action=schedule', 'success', 'Marked completed.');
        }
    }

    if ($op === 'cancel' && $canApprove && $item->status !== CARE_STATUS_COMPLETED) {
        db()->update('vehicle_care_schedules', [
            'status' => CARE_STATUS_CANCELLED,
            'updated_at' => date(DATETIME_FORMAT),
        ], 'id = ?', [$id]);
        notifyCareStakeholders(
            (int) $item->vehicle_id,
            'care_schedule_cancelled',
            'Care item cancelled',
            "{$item->title} for {$item->plate_number} was cancelled.",
            '/?page=maintenance&action=schedule'
        );
        auditLog('care_schedule_cancel', 'vehicle_care_schedule', $id);
        redirectWith('/?page=maintenance&action=schedule', 'success', 'Cancelled.');
    }

    $item = db()->fetch(
        "SELECT vcs.*, v.plate_number, v.make, v.model, v.mileage
         FROM vehicle_care_schedules vcs
         JOIN vehicles v ON v.id = vcs.vehicle_id
         WHERE vcs.id = ? AND vcs.deleted_at IS NULL",
        [$id]
    );
}

$statusInfo = CARE_STATUSES[$item->status] ?? ['label' => $item->status, 'color' => 'secondary'];
$typeInfo = CARE_TYPES[$item->care_type] ?? ['label' => $item->care_type];

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-start mb-4 gap-3">
        <div>
            <h4 class="mb-1"><i class="bi bi-card-checklist me-2"></i>Care #<?= (int) $item->id ?></h4>
            <p class="text-muted mb-0">
                <?= e($item->plate_number) ?> — <?= e($item->make . ' ' . $item->model) ?>
            </p>
        </div>
        <span class="badge bg-<?= e($statusInfo['color']) ?>"><?= e($statusInfo['label']) ?></span>
    </div>

    <?php foreach ($errors as $err): ?>
        <div class="alert alert-danger mb-3"><?= e($err) ?></div>
    <?php endforeach; ?>

    <div class="card mb-4">
        <div class="card-body p-4">
            <div class="mb-1"><strong>Type:</strong> <?= e($typeInfo['label']) ?></div>
            <div class="mb-1"><strong>Title:</strong> <?= e($item->title) ?></div>
            <div class="mb-1"><strong>Due:</strong> <?= formatDate($item->due_date) ?></div>
            <?php if ($item->notes): ?>
                <div><strong>Notes:</strong> <?= nl2br(e($item->notes)) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($canApprove && $item->status === CARE_STATUS_PENDING): ?>
        <form method="POST" class="mb-3">
            <?= csrfField() ?>
            <input type="hidden" name="op" value="approve">
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Approve & schedule</button>
        </form>
    <?php endif; ?>

    <?php if ($canApprove && !in_array($item->status, [CARE_STATUS_COMPLETED, CARE_STATUS_CANCELLED], true)): ?>
        <div class="card mb-4">
            <div class="card-body p-4">
                <h6 class="mb-3">Edit</h6>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="op" value="save">
                    <div class="mb-3">
                        <select name="care_type" class="form-select">
                            <?php foreach (CARE_TYPES as $k => $info): ?>
                                <option value="<?= e($k) ?>" <?= $item->care_type === $k ? 'selected' : '' ?>><?= e($info['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <input type="text" name="title" class="form-control" value="<?= e($item->title) ?>" required>
                    </div>
                    <div class="mb-3">
                        <input type="date" name="due_date" class="form-control" value="<?= e($item->due_date) ?>" required>
                    </div>
                    <div class="mb-3">
                        <textarea name="notes" class="form-control" rows="3"><?= e($item->notes ?? '') ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-outline-secondary">Save changes</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <div class="d-flex flex-wrap gap-2">
        <?php if ($canComplete && in_array($item->status, [CARE_STATUS_PENDING, CARE_STATUS_SCHEDULED], true)): ?>
            <form method="POST" class="mb-4">
                <?= csrfField() ?>
                <input type="hidden" name="op" value="complete">
                <div class="mb-3" style="max-width:18rem;">
                    <label class="form-label">Odometer (optional)</label>
                    <input type="number" name="completed_mileage" class="form-control" min="0"
                           value="<?= (int) ($item->mileage ?? 0) ?>">
                </div>
                <?php
                // Plan #38 — costing lines, only while Repair History is on.
                if (repairHistoryEnabled()):
                    $items = [];
                    $disabled = false;
                ?>
                <p class="small text-muted">
                    <i class="bi bi-tools me-1"></i>
                    Add costing below to record this care item in the vehicle Repair History.
                </p>
                <?php require PAGES_PATH . '/repair-history/partials/items_editor.php'; ?>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary">Mark completed</button>
            </form>
        <?php endif; ?>
        <?php if ($canApprove && $item->status !== CARE_STATUS_COMPLETED && $item->status !== CARE_STATUS_CANCELLED): ?>
            <form method="POST" onsubmit="return confirm('Cancel this care item?');">
                <?= csrfField() ?>
                <input type="hidden" name="op" value="cancel">
                <button type="submit" class="btn btn-outline-secondary">Cancel item</button>
            </form>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/?page=maintenance&action=schedule" class="btn btn-outline-secondary">Back</a>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
