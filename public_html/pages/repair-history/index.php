<?php
/**
 * LOKA - Repair History hub (Plan #38, experimental)
 *
 * All Father only (System Control). Shows the Enable switch while the feature
 * is off; once on, a per-vehicle roll-up with links to the history, print and
 * the reference-workbook importer.
 */

require_once INCLUDES_PATH . '/repair_history.php';
requireRole(ROLE_APPROVER);

// Plan #38 decision 2: the hub itself is reachable by Motorpool/Admin (they get
// links to it from the fleet menu when the feature is on), but only All Father
// can toggle the switch or run the importer.
if (!canViewRepairHistory()) {
    redirectWith('/?page=dashboard', 'danger', 'You do not have permission to view repair history.');
}
$isSystemControl = canAccessSystemControl();

$pageTitle = 'Repair History';
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $op = postSafe('op', '', 20);
    if ($op === 'toggle' && !$isSystemControl) {
        redirectWith('/?page=repair-history', 'danger', 'All Father access required to change this setting.');
    }
    if ($op === 'toggle') {
        repairHistorySetEnabled(post('enabled', '0') === '1');
        redirectWith(
            '/?page=repair-history',
            'success',
            repairHistoryEnabled()
                ? 'Repair History is now ON. New routes and the importer are available.'
                : 'Repair History is OFF. Data routes are blocked and auto-writes are skipped.'
        );
    }
}

$enabled = repairHistoryEnabled();
$vehicles = $enabled ? repairHistoryVehicleSummary() : [];
$search = trim(getSafe('search', '', 60));
if ($search !== '' && $enabled) {
    $needle = '%' . $search . '%';
    $vehicles = array_values(array_filter($vehicles, static function ($v) use ($needle) {
        return stripos((string) $v->plate_number, trim($needle, '%')) !== false
            || stripos((string) $v->make . ' ' . (string) $v->model, trim($needle, '%')) !== false;
    }));
}

$grandTotal = 0.0;
$grandEntries = 0;
foreach ($vehicles as $v) {
    $grandTotal += (float) $v->total_amount;
    $grandEntries += (int) $v->entry_count;
}

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid px-4 py-4">
    <div class="mb-2">
        <h4 class="mb-1"><i class="bi bi-tools me-2"></i>Repair History</h4>
        <p class="text-muted small mb-0">
            Experimental (Plan #38). Per-vehicle repair events with purchased-item costing,
            matching the DICT &ldquo;Motor Vehicle Repair History&rdquo; workbook.
        </p>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash[0]) ?>"><?= e($flash[1]) ?></div>
    <?php endif; ?>

    <?php if ($isSystemControl): ?>
    <div class="card mb-4">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            <div class="me-auto">
                <div class="fw-semibold">
                    <?= $enabled
                        ? '<span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>Enabled</span>'
                        : '<span class="text-secondary"><i class="bi bi-slash-circle me-1"></i>Disabled</span>' ?>
                </div>
                <div class="small text-muted">
                    When off: data routes are blocked, auto-writes from completed repair/care
                    items are skipped, and the importer is unavailable. Care and repair
                    <em>reminders</em> keep running regardless of this switch.
                </div>
            </div>
            <form method="POST" onsubmit="return confirm('Change the Repair History feature switch?');">
                <?= csrfField() ?>
                <input type="hidden" name="op" value="toggle">
                <input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
                <button type="submit" class="btn btn-<?= $enabled ? 'outline-danger' : 'primary' ?>">
                    <i class="bi bi-power me-1"></i><?= $enabled ? 'Disable' : 'Enable' ?>
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$enabled): ?>
        <div class="alert alert-info d-flex align-items-start" role="alert">
            <i class="bi bi-info-circle-fill flex-shrink-0 me-2"></i>
            <div>
                Repair History is an experimental module and is currently <strong>off</strong>.
                <?= $isSystemControl
                    ? 'Toggle it above to start recording manual entries, auto-capture completed
                       repair tickets and care items with costing, and import the reference workbooks.'
                    : 'An All Father must switch it on before history can be recorded or viewed.' ?>
            </div>
        </div>
    <?php else: ?>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card"><div class="card-body py-3 text-center">
                <div class="fs-4 fw-semibold"><?= count($vehicles) ?></div>
                <div class="small text-muted">Vehicles tracked</div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card"><div class="card-body py-3 text-center">
                <div class="fs-4 fw-semibold"><?= $grandEntries ?></div>
                <div class="small text-muted">Repair events</div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card"><div class="card-body py-3 text-center">
                <div class="fs-4 fw-semibold">₱<?= number_format($grandTotal, 2) ?></div>
                <div class="small text-muted">Recorded spend</div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card"><div class="card-body py-3 d-flex flex-column justify-content-center gap-1">
                <a class="btn btn-outline-primary btn-sm" href="<?= APP_URL ?>/?page=repair-history&action=create">
                    <i class="bi bi-plus-lg me-1"></i>Manual entry
                </a>
                <?php if ($isSystemControl): ?>
                <a class="btn btn-outline-secondary btn-sm" href="<?= APP_URL ?>/?page=repair-history&action=import">
                    <i class="bi bi-file-earmark-arrow-up me-1"></i>Import workbooks
                </a>
                <?php endif; ?>
            </div></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex flex-wrap align-items-center gap-2">
            <h5 class="mb-0 me-auto"><i class="bi bi-list-ul me-2"></i>Vehicles</h5>
            <form method="GET" class="d-flex gap-2">
                <input type="hidden" name="page" value="repair-history">
                <input type="text" name="search" class="form-control form-control-sm" style="width:16rem"
                       value="<?= e($search) ?>" placeholder="Plate, make or model...">
                <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
            </form>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Plate</th>
                            <th>Brand / Model</th>
                            <th>Engine No.</th>
                            <th class="text-center">Events</th>
                            <th class="text-end">Recorded spend</th>
                            <th>Last repair</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($vehicles === []): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">
                                No vehicles match. <?= $search !== '' ? 'Try a different search.' : 'Add a manual entry to start the history.' ?>
                            </td></tr>
                        <?php endif; ?>
                        <?php foreach ($vehicles as $v): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($v->plate_number) ?></td>
                            <td><?= e(trim(($v->make ?? '') . ' ' . ($v->model ?? ''))) ?: '—' ?></td>
                            <td class="font-monospace small"><?= e($v->engine_number ?: '—') ?></td>
                            <td class="text-center"><?= (int) $v->entry_count ?></td>
                            <td class="text-end"><?= (float) $v->total_amount > 0 ? '₱' . number_format((float) $v->total_amount, 2) : '—' ?></td>
                            <td class="text-nowrap"><?= $v->last_repair_date ? formatDate($v->last_repair_date) : '—' ?></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-primary"
                                   href="<?= APP_URL ?>/?page=repair-history&action=view&vehicle_id=<?= (int) $v->id ?>">History</a>
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="<?= APP_URL ?>/?page=repair-history&action=print&vehicle_id=<?= (int) $v->id ?>">Print</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>