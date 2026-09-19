<?php
/**
 * LOKA - OB Pass Slips list (Plans #22 + #27)
 *
 * Two workplaces (Plan #27):
 *  - Guards: the unbound-OB STAMP QUEUE only (no Apply, no my-slips table).
 *  - Admin / All Father: the gate stamp board on top of the full list + Apply.
 *  - Employees / department approvers: their own slips (no gate board).
 */

if (!function_exists('obFind')) {
    require_once INCLUDES_PATH . '/ob_requests.php';
}
requireAuth();

$pageTitle = 'OB Pass Slips';

$isGuardQueue = isGuard() && !isAdmin();
$showStampBoard = $isGuardQueue || isAdmin();

if (!$isGuardQueue) {
    $statusFilter = (string) get('status', '');

    $canSeeAll = isApprover(); // motorpool+ and admins
    $params = [];
    $where = 'o.deleted_at IS NULL';

    if (!$canSeeAll) {
        $where .= ' AND (o.user_id = ? OR o.supervisor_user_id = ?)';
        $params[] = userId();
        $params[] = userId();
    }
    if ($statusFilter !== '' && isset(OB_STATUSES[$statusFilter])) {
        $where .= ' AND o.status = ?';
        $params[] = $statusFilter;
    }

    $slips = db()->fetchAll(
        "SELECT o.*, u.name AS employee_name
         FROM ob_requests o
         JOIN users u ON o.user_id = u.id
         WHERE {$where}
         ORDER BY o.created_at DESC
         LIMIT 200",
        $params
    );
    $printedByOb = obPrintedLinesForIds(array_map(static fn($s) => (int) $s->id, $slips));
}

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h4 class="mb-1"><i class="bi bi-file-earmark-text me-2"></i>OB Pass Slips</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
                <li class="breadcrumb-item active">OB Pass Slips</li>
            </ol></nav>
            <?php if ($isGuardQueue): ?>
            <small class="text-muted">Gate stamp queue — Official Business slips waiting for departure / arrival stamps. Bound fleet trips stamp from the Guard Dashboard instead.</small>
            <?php else: ?>
            <small class="text-muted">Official Business Pass Slips — supervisor → motorpool → guard times → Certificate of Appearance.</small>
            <?php endif; ?>
        </div>
        <?php if (!$isGuardQueue): ?>
        <a href="<?= APP_URL ?>/?page=ob-requests&action=create" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Apply for OB
        </a>
        <?php endif; ?>
    </div>

    <?php if ($showStampBoard): ?>
    <?php require PAGES_PATH . '/guard/partials/ob_section.php'; // gate stamp queue (Plans #22 + #27) ?>
    <?php endif; ?>

    <?php if (!$isGuardQueue): ?>
    <form method="GET" class="card mb-4">
        <input type="hidden" name="page" value="ob-requests">
        <div class="card-body py-2">
            <div class="row g-2 align-items-center">
                <div class="col-auto">
                    <label class="form-label mb-0 small">Status</label>
                    <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
                        <option value="">All</option>
                        <?php foreach (OB_STATUSES as $key => $info): ?>
                        <option value="<?= e($key) ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= e($info['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto ms-auto">
                    <a href="<?= APP_URL ?>/?page=ob-requests" class="btn btn-sm btn-outline-secondary">Clear</a>
                </div>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="card-body p-0">
            <?php if (empty($slips)): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-inbox fs-1"></i>
                    <p class="mt-2 mb-0">No OB Pass Slips yet. Use <strong>Apply for OB</strong> to file one.</p>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light"><tr>
                        <th>Pass Slip No.</th><th>Date</th><th>Employee</th><th>Purpose</th>
                        <th>Plate</th><th>Status</th><th class="text-end">Actions</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($slips as $s): ?>
                        <tr>
                            <td class="text-nowrap"><strong><?= e($s->pass_slip_no) ?></strong></td>
                            <td class="text-nowrap"><?= e(date('M j, Y', strtotime($s->ob_date))) ?></td>
                            <td>
                                <?= e($s->employee_name) ?>
                                <?php if (!empty($printedByOb[(int) $s->id])): ?>
                                    <div class="small text-muted"><?= e($printedByOb[(int) $s->id]) ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="max-width:260px;"><span class="d-inline-block text-truncate" style="max-width:260px;"><?= e($s->purpose) ?></span></td>
                            <td><?= e($s->plate_number ?: '—') ?></td>
                            <td><span class="badge bg-<?= e(obStatusColor($s->status)) ?>"><?= e(obStatusLabel($s->status)) ?></span></td>
                            <td class="text-end text-nowrap">
                                <a href="<?= APP_URL ?>/?page=ob-requests&action=view&id=<?= (int) $s->id ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-eye me-1"></i>View
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
