<?php
/**
 * LOKA - Edit a manual repair-history entry (Plan #38, experimental)
 * Route: ?page=repair-history&action=edit&id=N
 */

require_once INCLUDES_PATH . '/repair_history.php';
requireRole(ROLE_APPROVER);

if (!repairHistoryEnabled()) {
    redirectWith('/?page=repair-history', 'warning', 'Repair History is disabled. An All Father must enable it first.');
}
if (!canManageRepairHistory()) {
    redirectWith('/?page=dashboard', 'danger', 'You do not have permission to edit repair history.');
}

$entryId = getInt('id');
$entry = $entryId
    ? db()->fetch(
        "SELECT e.*, v.plate_number FROM vehicle_repair_entries e
         JOIN vehicles v ON v.id = e.vehicle_id
         WHERE e.id = ? AND e.deleted_at IS NULL",
        [$entryId]
    )
    : null;
if (!$entry) {
    redirectWith('/?page=repair-history', 'danger', 'Repair history entry not found.');
}

$backUrl = '/?page=repair-history&action=view&vehicle_id=' . (int) $entry->vehicle_id;
$errors = [];

$existingItems = db()->fetchAll(
    "SELECT description, unit, quantity, amount, sort_order
     FROM vehicle_repair_items WHERE entry_id = ? ORDER BY sort_order ASC, id ASC",
    [$entryId]
);

$form = [
    'repair_date'      => (string) $entry->repair_date,
    'nature_of_repair' => (string) $entry->nature_of_repair,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $form = [
        'repair_date'      => postSafe('repair_date', '', 20),
        'nature_of_repair' => postSafe('nature_of_repair', '', 255),
    ];
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
        db()->update('vehicle_repair_entries', [
            'repair_date'      => date('Y-m-d', strtotime($form['repair_date'])),
            'nature_of_repair' => trim($form['nature_of_repair']),
            'updated_at'       => date(DATETIME_FORMAT),
        ], 'id = ?', [$entryId]);
        repairHistoryReplaceItems($entryId, $items);

        auditLog('repair_history_entry_updated', 'vehicle_repair_entry', $entryId, [
            'repair_date' => $entry->repair_date,
            'total'       => (float) $entry->total_amount,
        ], [
            'repair_date' => $form['repair_date'],
            'total'       => repairHistoryItemsTotal($items),
            'lines'       => count($items),
        ]);

        // Keep the linked repair ticket's rolled-up actual cost in step.
        if ($entry->maintenance_request_id) {
            db()->query(
                "UPDATE maintenance_requests SET actual_cost = ?, updated_at = NOW() WHERE id = ?",
                [repairHistoryItemsTotal($items), (int) $entry->maintenance_request_id]
            );
        }

        redirectWith($backUrl, 'success', 'Repair history entry updated.');
    }

    $existingItems = repairHistoryNormalizeItems(repairHistoryItemsFromPost())[0] ?: $existingItems;
}

$pageTitle = 'Edit repair entry';
require_once INCLUDES_PATH . '/header.php';
$items = $existingItems;
?>

<div class="container px-4 py-4">
    <div class="d-flex flex-wrap align-items-start gap-3 mb-4">
        <div class="me-auto">
            <h4 class="mb-1"><i class="bi bi-pencil-square me-2"></i>Edit Repair Entry</h4>
            <p class="text-muted mb-0 small">
                <?= e($entry->plate_number) ?> · source <?= repairHistoryStatusBadge((string) $entry->source) ?>
            </p>
        </div>
        <a class="btn btn-outline-secondary" href="<?= APP_URL ?><?= e($backUrl) ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>

    <?php if ($entry->source !== 'manual'): ?>
        <div class="alert alert-warning small">
            This entry was recorded automatically from <?= e($entry->source) ?>.
            Editing it here is allowed for correction, but re-completing the source
            record will not overwrite your changes (the auto-write is skipped when an
            entry already exists).
        </div>
    <?php endif; ?>

    <?php foreach ($errors as $err): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= e($err) ?></div>
    <?php endforeach; ?>

    <form method="POST">
        <?= csrfField() ?>

        <div class="card mb-4">
            <div class="card-body">
                <h6 class="mb-3">Repair event</h6>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label" for="repair_date">Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="repair_date" name="repair_date"
                               value="<?= e($form['repair_date']) ?>" required>
                    </div>
                    <div class="col-md-9">
                        <label class="form-label" for="nature_of_repair">Nature of Repair <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="nature_of_repair" name="nature_of_repair"
                               maxlength="255" required value="<?= e($form['nature_of_repair']) ?>">
                    </div>
                </div>
            </div>
        </div>

        <?php require __DIR__ . '/partials/items_editor.php'; ?>

        <div class="d-flex gap-2 justify-content-end mb-4">
            <a class="btn btn-outline-secondary btn-lg" href="<?= APP_URL ?><?= e($backUrl) ?>">Cancel</a>
            <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-save me-1"></i>Save changes</button>
        </div>
    </form>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>