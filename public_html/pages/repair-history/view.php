<?php
/**
 * LOKA - Per-vehicle repair history (Plan #38, experimental)
 * Route: ?page=repair-history&action=view&vehicle_id=N
 */

require_once INCLUDES_PATH . '/repair_history.php';
requireRole(ROLE_APPROVER);

if (!repairHistoryEnabled()) {
    redirectWith('/?page=repair-history', 'warning', 'Repair History is disabled. An All Father must enable it first.');
}
if (!canViewRepairHistory()) {
    redirectWith('/?page=dashboard', 'danger', 'You do not have permission to view repair history.');
}

$vehicleId = getInt('vehicle_id');
$vehicle = $vehicleId
    ? db()->fetch(
        "SELECT v.*, vt.name AS type_name
         FROM vehicles v
         LEFT JOIN vehicle_types vt ON vt.id = v.vehicle_type_id
         WHERE v.id = ? AND v.deleted_at IS NULL",
        [$vehicleId]
    )
    : null;
if (!$vehicle) {
    redirectWith('/?page=repair-history', 'danger', 'Vehicle not found.');
}

$history = repairHistoryForVehicle((int) $vehicle->id);
$canManage = canManageRepairHistory();

$pageTitle = 'Repair history — ' . $vehicle->plate_number;
require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid px-4 py-4">
    <div class="d-flex flex-wrap align-items-start gap-3 mb-4">
        <div class="me-auto">
            <h4 class="mb-1"><i class="bi bi-tools me-2"></i>Repair History — <?= e($vehicle->plate_number) ?></h4>
            <p class="text-muted mb-0 small">
                <?= e(trim(($vehicle->make ?? '') . ' ' . ($vehicle->model ?? ''))) ?>
                <?php if (!empty($vehicle->type_name)): ?> · <?= e($vehicle->type_name) ?><?php endif; ?>
                <?php if (!empty($vehicle->engine_number)): ?> · Engine <span class="font-monospace"><?= e($vehicle->engine_number) ?></span><?php endif; ?>
            </p>
        </div>
        <div class="btn-group">
            <?php if ($canManage): ?>
            <a class="btn btn-primary" href="<?= APP_URL ?>/?page=repair-history&action=create&vehicle_id=<?= (int) $vehicle->id ?>">
                <i class="bi bi-plus-lg me-1"></i>Add entry
            </a>
            <?php endif; ?>
            <a class="btn btn-outline-secondary" href="<?= APP_URL ?>/?page=repair-history&action=print&vehicle_id=<?= (int) $vehicle->id ?>">
                <i class="bi bi-printer me-1"></i>Print sheet
            </a>
            <a class="btn btn-outline-secondary" href="<?= APP_URL ?>/?page=repair-history">
                <i class="bi bi-arrow-left me-1"></i>Hub
            </a>
        </div>
    </div>

    <?php if ($history === []): ?>
        <div class="alert alert-info d-flex align-items-start" role="alert">
            <i class="bi bi-info-circle-fill flex-shrink-0 me-2"></i>
            <div>No repair history recorded for <strong><?= e($vehicle->plate_number) ?></strong> yet.
            <?php if ($canManage): ?>Use <em>Add entry</em>, or let the feature auto-capture completed repair tickets and care items with costing.<?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php foreach ($history as $row): $entry = $row['entry']; ?>
        <div class="card mb-3">
            <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <span class="badge bg-primary fs-6"><?= formatDate($entry->repair_date) ?></span>
                <strong class="me-auto"><?= e($entry->nature_of_repair) ?></strong>
                <?= repairHistoryStatusBadge((string) $entry->source) ?>
                <span class="badge bg-success">₱<?= number_format((float) $entry->total_amount, 2) ?></span>
                <?php if ($canManage): ?>
                <div class="btn-group btn-group-sm">
                    <a class="btn btn-outline-secondary" href="<?= APP_URL ?>/?page=repair-history&action=edit&id=<?= (int) $entry->id ?>">
                        <i class="bi bi-pencil"></i>
                    </a>
                    <form method="POST" action="<?= APP_URL ?>/?page=repair-history&action=delete"
                          onsubmit="return confirm('Soft-delete this repair entry? It disappears from the history and print sheet.');">
                        <?= csrfField() ?>
                        <input type="hidden" name="id" value="<?= (int) $entry->id ?>">
                        <input type="hidden" name="vehicle_id" value="<?= (int) $vehicle->id ?>">
                        <button type="submit" class="btn btn-outline-danger" title="Soft-delete"><i class="bi bi-trash"></i></button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th style="width:10%">Unit</th>
                                <th style="width:10%" class="text-end">Quantity</th>
                                <th style="width:15%" class="text-end">Price</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($row['items'] === []): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">No line items recorded.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($row['items'] as $item): ?>
                            <tr>
                                <td><?= e($item->description) ?></td>
                                <td><?= e($item->unit ?: '—') ?></td>
                                <td class="text-end"><?= e(rtrim(rtrim(number_format((float) $item->quantity, 2, '.', ''), '0'), '.')) ?></td>
                                <td class="text-end"><?= $item->amount !== null ? '₱' . number_format((float) $item->amount, 2) : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($entry->maintenance_request_id || $entry->care_schedule_id): ?>
            <div class="card-footer bg-white small text-muted py-2">
                <?php if ($entry->maintenance_request_id): ?>
                    Linked to <a href="<?= APP_URL ?>/?page=maintenance&action=view&id=<?= (int) $entry->maintenance_request_id ?>">repair ticket #<?= (int) $entry->maintenance_request_id ?></a>
                <?php elseif ($entry->care_schedule_id): ?>
                    Linked to <a href="<?= APP_URL ?>/?page=maintenance&action=care-edit&id=<?= (int) $entry->care_schedule_id ?>">care item #<?= (int) $entry->care_schedule_id ?></a>
                <?php endif; ?>
                <?php if (!empty($entry->created_by_name)): ?> · recorded by <?= e($entry->created_by_name) ?><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>