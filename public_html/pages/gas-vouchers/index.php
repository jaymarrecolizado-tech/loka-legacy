<?php
/**
 * LOKA - Gas Vouchers List Page
 */

if (!canAccessGasVouchers()) {
    redirectWith('/?page=dashboard', 'danger', 'You do not have permission to access this page.');
}

$pageTitle = 'Gas Vouchers';

// Determine what the user can see
$statusFilter = getSafe('status', '', 20);
$searchFilter = listSearchQuery();
$dateFrom     = getSafe('date_from', '', 20);
$dateTo       = getSafe('date_to', '', 20);

$whereClause = 'gv.deleted_at IS NULL';
$params = [];

// Non-admins see only their own vouchers unless they are approvers or Chief Admin/Finance
if (!isAdmin() && !isMotorpool() && !isApprover() && !isChiefAdminFinance()) {
    $whereClause .= ' AND gv.requested_by_user_id = ?';
    $params[] = userId();
}

if ($statusFilter) {
    $whereClause .= ' AND gv.status = ?';
    $params[] = $statusFilter;
}

if ($searchFilter) {
    $whereClause .= ' AND (gv.voucher_no LIKE ? OR gv.vehicle_plate LIKE ? OR gv.driver_name LIKE ? OR gv.purpose LIKE ? OR gv.fund_source LIKE ?)';
    $params = array_merge($params, ["%$searchFilter%", "%$searchFilter%", "%$searchFilter%", "%$searchFilter%", "%$searchFilter%"]);
}

if ($dateFrom) {
    $whereClause .= ' AND gv.request_date >= ?';
    $params[] = $dateFrom;
}
if ($dateTo) {
    $whereClause .= ' AND gv.request_date <= ?';
    $params[] = $dateTo;
}

// Sorting (latest created first by default)
$allowedSortColumns = [
    'voucher_no' => 'gv.voucher_no',
    'request_date' => 'gv.request_date',
    'created_at' => 'gv.created_at',
    'status' => 'gv.status',
    'vehicle_plate' => 'gv.vehicle_plate',
    'driver_name' => 'gv.driver_name',
    'requester' => 'u.name',
];
$sortState = resolveTableSort($allowedSortColumns, 'created_at', 'DESC');
$sort = $sortState['key'];
$sortDir = $sortState['dir'];

$countRow = db()->fetch(
    "SELECT COUNT(*) as c FROM gas_vouchers gv WHERE {$whereClause}",
    $params
);
$pag = listPaginationState((int) ($countRow->c ?? 0));

$vouchers = db()->fetchAll(
    "SELECT gv.*,
            u.name AS requester_name,
            reviewer.name AS reviewer_name,
            approver.name AS approver_name_full
     FROM gas_vouchers gv
     JOIN users u ON gv.requested_by_user_id = u.id
     LEFT JOIN users reviewer ON gv.reviewed_by = reviewer.id
     LEFT JOIN users approver ON gv.approved_by = approver.id
     WHERE {$whereClause}
     ORDER BY {$sortState['orderSql']}
     LIMIT ? OFFSET ?",
    array_merge($params, [$pag['perPage'], $pag['offset']])
);

$baseParams = tableSortQueryParams($sortState, [
    'page' => 'gas-vouchers',
    'status' => $statusFilter,
    'q' => $searchFilter,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'per_page' => $pag['perPage'],
]);

// Count by status for summary badges
$statusCounts = db()->fetchAll(
    "SELECT status, COUNT(*) as cnt FROM gas_vouchers WHERE deleted_at IS NULL GROUP BY status"
);
$counts = [];
foreach ($statusCounts as $s) {
    $counts[$s->status] = $s->cnt;
}

// Pending review (for OIC Motorpool / Motorpool Head)
$pendingReviewCount = 0;
if (isMotorpool() || isApprover() || isAdmin() || isChiefAdminFinance()) {
    $pendingReviewCount = $counts['pending_review'] ?? 0;
}

// Pending approval (for Chief Admin & Finance / Admin role)
$pendingApprovalCount = 0;
if (isAdmin() || isMotorpool() || isChiefAdminFinance()) {
    $pendingApprovalCount = $counts['pending_approval'] ?? 0;
}

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">

    <!-- Page Header -->
    <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between gap-4 mb-4">
        <div>
            <h1 class="fs-3 fw-bold d-flex align-items-center gap-2">
                <i class="bi bi-fuel-pump"></i>Gas Vouchers
            </h1>
            <div class="small text-muted mt-1">
                <a href="<?= APP_URL ?>" class="">Dashboard</a>
                <span class="mx-1">/</span>
                <span>Gas Vouchers</span>
            </div>
        </div>
        <a href="<?= APP_URL ?>/?page=gas-vouchers&action=create" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>New Gas Voucher
        </a>
    </div>

    <!-- Summary Cards -->
    <div class="row g-4 mb-4">
        <?php $gvStats = [
            ['Pending Review', $counts['pending_review'] ?? 0, 'text-warning'],
            ['Pending Approval', $counts['pending_approval'] ?? 0, 'text-info'],
            ['Approved', $counts['approved'] ?? 0, 'text-success'],
            ['Total Vouchers', array_sum($counts), 'text-secondary'],
        ]; ?>
        <?php foreach ($gvStats as [$gvLabel, $gvVal, $gvColor]): ?>
        <div class="col-6 col-xl-3">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="stat-value text-<?= $gvColor ?>"><?= $gvVal ?></div>
                    <div class="stat-label"><?= $gvLabel ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <form method="GET" class="row g-3 mb-3">
            <input type="hidden" name="page" value="gas-vouchers">

            <div class="d-flex flex-column gap-2 min-w-140">
                <label class="form-label small fw-semibold text-muted text-uppercase">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="pending_review" <?= $statusFilter === 'pending_review' ? 'selected' : '' ?>>Pending Review</option>
                    <option value="pending_approval" <?= $statusFilter === 'pending_approval' ? 'selected' : '' ?>>Pending Approval</option>
                    <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>

            <?= listSearchFieldHtml($searchFilter, 'Voucher no, plate, driver, purpose...') ?>

            <div class="d-flex flex-column gap-2 min-w-140">
                <label class="form-label small fw-semibold text-muted text-uppercase">Date From</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= e($dateFrom) ?>">
            </div>
            <div class="d-flex flex-column gap-2 min-w-140">
                <label class="form-label small fw-semibold text-muted text-uppercase">Date To</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= e($dateTo) ?>">
            </div>

            <?= perPageFieldHtml($pag['perPage']) ?>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-search me-1"></i>Filter
                </button>
                <a href="<?= APP_URL ?>/?page=gas-vouchers" class="btn btn-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>

    <!-- Vouchers Table -->
    <div class="card">
        <?php if (empty($vouchers)): ?>
            <div class="text-center text-muted py-5 empty-state">
                <i class="bi bi-fuel-pump fs-1 text-muted"></i>
                <h3 class="mt-3 fs-5 fw-semibold">No gas vouchers found</h3>
                <p class="small text-muted">Create your first gas voucher request to get started.</p>
                <a href="<?= APP_URL ?>/?page=gas-vouchers&action=create" class="btn btn-primary mt-2">
                    <i class="bi bi-plus-lg me-1"></i>New Gas Voucher
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle">
                    <thead>
                        <tr>
                            <?= tableSortTh('voucher_no', 'Voucher No.', $sort, $sortDir, $baseParams) ?>
                            <?= tableSortTh('request_date', 'Date', $sort, $sortDir, $baseParams) ?>
                            <?= tableSortTh('driver_name', 'Driver / Vehicle', $sort, $sortDir, $baseParams) ?>
                            <th>Fuel</th>
                            <th>Fund Source</th>
                            <th>Purpose</th>
                            <?= tableSortTh('status', 'Status', $sort, $sortDir, $baseParams) ?>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($vouchers as $v): ?>
                        <tr>
                            <td>
                                <div class="fw-bold text-primary"><?= e($v->voucher_no) ?></div>
                                <?php if (!isAdmin() && !isApprover() && !isMotorpool() && !isChiefAdminFinance()): ?>
                                <div class="small text-muted">by Me</div>
                                <?php else: ?>
                                <div class="small text-muted"><?= e($v->requester_name) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= e(date('M d, Y', strtotime($v->request_date))) ?>
                                <?php if ($v->date_withdrawn): ?>
                                <div class="small text-muted">Withdrawn: <?= e(date('M d, Y', strtotime($v->date_withdrawn))) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-semibold"><?= e($v->driver_name) ?></div>
                                <span class="badge bg-secondary small"><?= e($v->vehicle_plate) ?></span>
                            </td>
                            <td>
                                <div><?= e($v->quantity) ?> <?= e($v->unit) ?></div>
                                <div class="small text-muted"><?= e($v->fuel_type) ?></div>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark badge-secondary"><?= e($v->fund_source) ?></span>
                            </td>
                            <td>
                                <span title="<?= e($v->purpose) ?>"><?= e(mb_substr($v->purpose, 0, 40)) ?><?= strlen($v->purpose) > 40 ? '…' : '' ?></span>
                            </td>
                            <td>
                                <?= gasVoucherStatusBadge($v->status) ?>
                            </td>
                            <td>
                                <div class="d-flex flex-wrap gap-1">
                                    <a href="<?= APP_URL ?>/?page=gas-vouchers&action=view&id=<?= $v->id ?>"
                                       class="btn btn-sm btn btn-light text-primary" title="View">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php if ($v->status === 'draft' && ($v->requested_by_user_id == userId() || isAdmin())): ?>
                                    <a href="<?= APP_URL ?>/?page=gas-vouchers&action=edit&id=<?= $v->id ?>"
                                       class="btn btn-sm btn btn-light" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($v->status === 'approved'): ?>
                                    <a href="<?= APP_URL ?>/?page=gas-vouchers&action=print&id=<?= $v->id ?>"
                                       class="btn btn-sm btn btn-light text-success" title="Print Voucher" target="_blank">
                                        <i class="bi bi-printer"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if (($v->status === 'pending_review' && (isMotorpool() || isApprover() || isAdmin() || isChiefAdminFinance())) ||
                                              ($v->status === 'pending_approval' && (isAdmin() || isMotorpool() || isChiefAdminFinance()))): ?>
                                    <a href="<?= APP_URL ?>/?page=gas-vouchers&action=approve&id=<?= $v->id ?>"
                                       class="btn btn-sm btn btn-light text-warning" title="Process">
                                        <i class="bi bi-check-circle"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= listPaginationFooter($pag, $baseParams) ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>


