<?php
/**
 * LOKA - Manual repair-history entry (Plan #38, experimental)
 * Route: ?page=repair-history&action=create
 */

require_once INCLUDES_PATH . '/repair_history.php';
requireRole(ROLE_APPROVER);

if (!repairHistoryEnabled()) {
    redirectWith('/?page=repair-history', 'warning', 'Repair History is disabled. An All Father must enable it first.');
}
if (!canManageRepairHistory()) {
    redirectWith('/?page=dashboard', 'danger', 'You do not have permission to record repair history.');
}

$errors = [];
$vehicles = db()->fetchAll(
    "SELECT id, plate_number, make, model, engine_number FROM vehicles
     WHERE deleted_at IS NULL ORDER BY plate_number ASC"
);
$form = [
    'vehicle_id'      => getInt('vehicle_id'),
    'repair_date'     => date('Y-m-d'),
    'nature_of_repair' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $form = [
        'vehicle_id'       => postInt('vehicle_id'),
        'repair_date'      => postSafe('repair_date', '', 20),
        'nature_of_repair' => postSafe('nature_of_repair', '', 255),
    ];

    $vehicle = $form['vehicle_id']
        ? db()->fetch("SELECT id, plate_number FROM vehicles WHERE id = ? AND deleted_at IS NULL", [$form['vehicle_id']])
        : null;
    if (!$vehicle) {
        $errors[] = 'Please choose a vehicle.';
    }
    if (!strtotime($form['repair_date'])) {
        $errors[] = 'A valid repair date is required.';
    }
    if (trim($form['nature_of_repair']) === '') {
        $errors[] = 'Nature of Repair is required.';
    }

    [$items, $itemErrors] = repairHistoryNormalizeItems(repairHistoryItemsFromPost());
    $errors = array_merge($errors, $itemErrors);
    if ($items === []) {
        $errors[] = 'Add at least one purchased-item line.';
    }

    if (!$errors) {
        $entryId = repairHistoryCreateEntry(
            (int) $vehicle->id,
            date('Y-m-d', strtotime($form['repair_date'])),
            trim($form['nature_of_repair']),
            $items,
            'manual'
        );
        auditLog('repair_history_entry_created', 'vehicle_repair_entry', $entryId, null, [
            'vehicle_id'  => (int) $vehicle->id,
            'plate'       => $vehicle->plate_number,
            'repair_date' => $form['repair_date'],
            'total'       => repairHistoryItemsTotal($items),
            'lines'       => count($items),
        ]);
        redirectWith(
            '/?page=repair-history&action=view&vehicle_id=' . (int) $vehicle->id,
            'success',
            'Repair history entry saved (₱' . number_format(repairHistoryItemsTotal($items), 2) . ').'
        );
    }
}

$pageTitle = 'New repair history entry';
require_once INCLUDES_PATH . '/header.php';
?>

<div class="container px-4 py-4">
    <div class="d-flex flex-wrap align-items-start gap-3 mb-4">
        <div class="me-auto">
            <h4 class="mb-1"><i class="bi bi-plus-circle me-2"></i>Manual Repair Entry</h4>
            <p class="text-muted mb-0 small">Source is recorded as <strong>Manual entry</strong>; no repair ticket is required.</p>
        </div>
        <a class="btn btn-outline-secondary" href="<?= APP_URL ?>/?page=repair-history"><i class="bi bi-arrow-left me-1"></i>Back to hub</a>
    </div>

    <?php foreach ($errors as $err): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= e($err) ?></div>
    <?php endforeach; ?>

    <form method="POST">
        <?= csrfField() ?>

        <div class="card mb-4">
            <div class="card-body">
                <h6 class="mb-3">Repair event</h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="vehicle_id">Vehicle <span class="text-danger">*</span></label>
                        <select class="form-select" id="vehicle_id" name="vehicle_id" required>
                            <option value="">Select a vehicle...</option>
                            <?php foreach ($vehicles as $v): ?>
                                <option value="<?= (int) $v->id ?>" <?= (int) $form['vehicle_id'] === (int) $v->id ? 'selected' : '' ?>>
                                    <?= e($v->plate_number . ' — ' . trim($v->make . ' ' . $v->model)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="repair_date">Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="repair_date" name="repair_date"
                               value="<?= e($form['repair_date']) ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Engine No. <span class="text-muted small">(from vehicle master)</span></label>
                        <div class="form-control bg-light small text-muted">Filled automatically on the print sheet.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="nature_of_repair">Nature of Repair <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="nature_of_repair" name="nature_of_repair"
                               maxlength="255" required value="<?= e($form['nature_of_repair']) ?>"
                               placeholder="e.g. Change oil / Replacement of front brake pads">
                    </div>
                </div>
            </div>
        </div>

        <?php require __DIR__ . '/partials/items_editor.php'; ?>

        <div class="d-flex gap-2 justify-content-end mb-4">
            <a class="btn btn-outline-secondary btn-lg" href="<?= APP_URL ?>/?page=repair-history">Cancel</a>
            <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-save me-1"></i>Save entry</button>
        </div>
    </form>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>