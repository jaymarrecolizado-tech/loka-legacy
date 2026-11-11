<?php
/**
 * LOKA - Workflow Rollback Hub (Admin, Plan #39)
 *
 * One hub, three tabs: Trips | OB | Gas. Each row shows how many earlier
 * workflow stages it can be rolled back to, so an admin can see at a glance
 * whether a record is actionable before opening the picker.
 */

require_once INCLUDES_PATH . '/rollback.php';
requireRole(ROLE_ADMIN);

$pageTitle = 'Workflow Rollback';
$tab = get('tab', 'trips');
if (!in_array($tab, ['trips', 'ob', 'gas'], true)) {
    $tab = 'trips';
}

$startDate = get('start_date', '');
$endDate = get('end_date', '');
$filterStatus = get('status', '');
$filterDept = get('department_id', '');
$search = trim(getSafe('search', '', 60));

$flash = null;
$tabs = ['trips' => 'Trips', 'ob' => 'OB Pass Slips', 'gas' => 'Gas Vouchers'];

// =====================================================================
// Trips
// =====================================================================
if ($tab === 'trips') {
    $rollbackableStatuses = [STATUS_PENDING_MOTORPOOL, STATUS_APPROVED, STATUS_COMPLETED, STATUS_REVISION, STATUS_REJECTED];

    $allDepartments = db()->fetchAll("SELECT id, name FROM departments WHERE deleted_at IS NULL ORDER BY name");

    $summary = [];
    $statusListSql = implode("','", $rollbackableStatuses);
    foreach (db()->fetchAll(
        "SELECT status, COUNT(*) AS cnt FROM requests
         WHERE deleted_at IS NULL AND status IN ('$statusListSql')
         GROUP BY status"
    ) as $row) {
        $summary[$row->status] = (int) $row->cnt;
    }

    $where = ['r.deleted_at IS NULL', "r.status IN ('$statusListSql')"];
    $params = [];
    if ($filterStatus && in_array($filterStatus, $rollbackableStatuses, true)) {
        $where[] = 'r.status = ?';
        $params[] = $filterStatus;
    }
    if ($filterDept) {
        $where[] = 'r.department_id = ?';
        $params[] = $filterDept;
    }
    if ($search !== '') {
        $where[] = '(r.destination LIKE ? OR r.purpose LIKE ? OR u.name LIKE ? OR v.plate_number LIKE ?)';
        $term = '%' . $search . '%';
        array_push($params, $term, $term, $term, $term);
    }
    if ($startDate) {
        $where[] = 'r.start_datetime >= ?';
        $params[] = $startDate;
    }
    if ($endDate) {
        $where[] = 'r.start_datetime <= ?';
        $params[] = $endDate . ' 23:59:59';
    }

    $requests = db()->fetchAll(
        "SELECT r.id, r.status, r.start_datetime, r.destination, r.purpose, r.rollback_count,
                r.actual_dispatch_datetime, r.actual_arrival_datetime,
                u.name AS requester_name, dept.name AS department_name,
                v.plate_number, du.name AS driver_name
         FROM requests r
         JOIN users u ON r.user_id = u.id
         LEFT JOIN departments dept ON r.department_id = dept.id
         LEFT JOIN vehicles v ON r.vehicle_id = v.id
         LEFT JOIN drivers d ON r.driver_id = d.id
         LEFT JOIN users du ON d.user_id = du.id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY r.updated_at DESC
         LIMIT 500",
        $params
    );

    $cards = [
        STATUS_PENDING_MOTORPOOL => ['Pending Motorpool', 'info'],
        STATUS_APPROVED          => ['Approved', 'success'],
        STATUS_COMPLETED         => ['Completed', 'primary'],
        STATUS_REVISION          => ['For Revision', 'warning'],
        STATUS_REJECTED          => ['Rejected', 'danger'],
    ];
}

// =====================================================================
// OB Pass Slips
// =====================================================================
if ($tab === 'ob') {
    if (!function_exists('obFind')) {
        require_once INCLUDES_PATH . '/ob_requests.php';
    }
    $obStatuses = array_keys(ROLLBACK_OB_STAGES);
    $obStatusSql = implode("','", $obStatuses);

    $where = ['o.deleted_at IS NULL', "o.status IN ('$obStatusSql')"];
    $params = [];
    if ($filterStatus && in_array($filterStatus, $obStatuses, true)) {
        $where[] = 'o.status = ?';
        $params[] = $filterStatus;
    }
    if ($startDate) {
        $where[] = 'o.ob_date >= ?';
        $params[] = $startDate;
    }
    if ($endDate) {
        $where[] = 'o.ob_date <= ?';
        $params[] = $endDate;
    }
    if ($search !== '') {
        $where[] = '(o.pass_slip_no LIKE ? OR o.purpose LIKE ? OR u.name LIKE ? OR o.plate_number LIKE ?)';
        $term = '%' . $search . '%';
        array_push($params, $term, $term, $term, $term);
    }

    $obRows = db()->fetchAll(
        "SELECT o.*, u.name AS employee_name
         FROM ob_requests o
         JOIN users u ON o.user_id = u.id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY o.ob_date DESC, o.id DESC
         LIMIT 500",
        $params
    );

    $obCards = [];
    foreach (ROLLBACK_OB_STAGES as $st => $meta) {
        $obCards[$st] = [$meta['label'], 'secondary'];
    }
}

// =====================================================================
// Gas vouchers
// =====================================================================
if ($tab === 'gas') {
    $gasStatuses = array_keys(ROLLBACK_GAS_STAGES);
    $gasStatusSql = implode("','", $gasStatuses);

    $where = ['v.deleted_at IS NULL', "v.status IN ('$gasStatusSql')"];
    $params = [];
    if ($filterStatus && in_array($filterStatus, $gasStatuses, true)) {
        $where[] = 'v.status = ?';
        $params[] = $filterStatus;
    }
    if ($startDate) {
        $where[] = 'v.request_date >= ?';
        $params[] = $startDate;
    }
    if ($endDate) {
        $where[] = 'v.request_date <= ?';
        $params[] = $endDate;
    }
    if ($search !== '') {
        $where[] = '(v.voucher_no LIKE ? OR v.purpose LIKE ? OR v.driver_name LIKE ? OR v.vehicle_plate LIKE ?)';
        $term = '%' . $search . '%';
        array_push($params, $term, $term, $term, $term);
    }

    $gasRows = db()->fetchAll(
        "SELECT v.*, u.name AS requester_name
         FROM gas_vouchers v
         JOIN users u ON v.requested_by_user_id = u.id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY v.request_date DESC, v.id DESC
         LIMIT 500",
        $params
    );

    $gasCards = [];
    foreach (ROLLBACK_GAS_STAGES as $st => $meta) {
        $gasCards[$st] = [$meta['label'], 'secondary'];
    }
}

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="mb-4">
        <h4 class="mb-1"><i class="bi bi-arrow-counterclockwise me-2"></i>Workflow Rollback</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
                <li class="breadcrumb-item active">Workflow Rollback</li>
            </ol>
        </nav>
    </div>

    <ul class="nav nav-tabs mb-4">
        <?php foreach ($tabs as $key => $label): ?>
            <li class="nav-item">
                <a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="<?= APP_URL ?>/?page=rollback&tab=<?= $key ?>">
                    <i class="bi bi-<?= $key === 'trips' ? 'truck' : ($key === 'ob' ? 'briefcase' : 'cash-coin') ?> me-1"></i><?= e($label) ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <!-- Summary cards -->
    <div class="row g-3 mb-4">
        <?php
        if ($tab === 'trips'):
            foreach ($cards as $st => [$label, $color]):
                ?>
                <div class="col">
                    <a href="?page=rollback&tab=trips&status=<?= $st ?>" class="text-decoration-none">
                        <div class="card bg-<?= $color === 'warning' ? 'warning' : $color ?> bg-opacity-10">
                            <div class="card-body text-center py-2">
                                <h4 class="text-<?= $color === 'warning' ? 'warning' : $color ?> mb-0"><?= $summary[$st] ?? 0 ?></h4>
                                <small class="text-muted"><?= $label ?></small>
                            </div>
                        </div>
                    </a>
                </div>
                <?php endforeach;
        elseif ($tab === 'ob'):
            foreach ($obRows as $o) {
                $obCards[$o->status][1] = $obCards[$o->status][1] ?? 'secondary';
            }
            foreach ($obCards as $st => [$label, $color]):
                $cnt = count(array_filter($obRows, static fn($o) => $o->status === $st));
                ?>
                <div class="col">
                    <a href="?page=rollback&tab=ob&status=<?= $st ?>" class="text-decoration-none">
                        <div class="card bg-<?= $color === 'warning' ? 'warning' : $color ?> bg-opacity-10">
                            <div class="card-body text-center py-2">
                                <h4 class="text-<?= $color === 'warning' ? 'warning' : $color ?> mb-0"><?= $cnt ?></h4>
                                <small class="text-muted"><?= $label ?></small>
                            </div>
                        </div>
                    </a>
                </div>
                <?php endforeach;
        else:
            foreach ($gasCards as $st => [$label, $color]):
                $cnt = count(array_filter($gasRows, static fn($g) => $g->status === $st));
                ?>
                <div class="col">
                    <a href="?page=rollback&tab=gas&status=<?= $st ?>" class="text-decoration-none">
                        <div class="card bg-secondary bg-opacity-10">
                            <div class="card-body text-center py-2">
                                <h4 class="text-secondary mb-0"><?= $cnt ?></h4>
                                <small class="text-muted"><?= $label ?></small>
                            </div>
                        </div>
                    </a>
                </div>
                <?php endforeach;
        endif;
        ?>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="page" value="rollback">
                <input type="hidden" name="tab" value="<?= e($tab) ?>">
                <div class="col-md-3">
                    <label class="form-label">Search</label>
                    <input type="text" class="form-control" name="search" value="<?= e($search) ?>"
                        placeholder="<?= $tab === 'trips' ? 'Destination, purpose, requester, plate' : ($tab === 'ob' ? 'Slip no, purpose, employee, plate' : 'Voucher no, purpose, driver, plate') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Stage</label>
                    <select class="form-select" name="status">
                        <option value="">All stages</option>
                        <?php
                        $stageOptions = $tab === 'trips' ? array_keys($cards)
                            : ($tab === 'ob' ? array_keys(ROLLBACK_OB_STAGES) : array_keys(ROLLBACK_GAS_STAGES));
                        foreach ($stageOptions as $st): ?>
                            <option value="<?= e($st) ?>" <?= $filterStatus === $st ? 'selected' : '' ?>><?= e(rollbackStageLabel($st)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($tab === 'trips'): ?>
                <div class="col-md-2">
                    <label class="form-label">Department</label>
                    <select class="form-select" name="department_id">
                        <option value="">All Departments</option>
                        <?php foreach ($allDepartments as $dept): ?>
                            <option value="<?= $dept->id ?>" <?= $filterDept == $dept->id ? 'selected' : '' ?>><?= e($dept->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-2">
                    <label class="form-label">From</label>
                    <input type="date" class="form-control" name="start_date" value="<?= e($startDate) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">To</label>
                    <input type="date" class="form-control" name="end_date" value="<?= e($endDate) ?>">
                </div>
                <div class="col-md-1 d-grid">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Rows -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i><?= e($tabs[$tab]) ?></h5>
            <small class="text-muted"><?= $tab === 'trips' ? count($requests) : ($tab === 'ob' ? count($obRows) : count($gasRows)) ?> record(s)</small>
        </div>
        <div class="card-body p-0">

            <?php if ($tab === 'trips'): ?>
            <?php if (empty($requests)): ?>
                <div class="text-center py-5 text-muted"><i class="bi bi-check2-circle fs-1"></i><p class="mt-2">No roll-backable requests found.</p></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>ID</th><th>Stage</th><th>Status</th><th>Scheduled</th><th>Requester</th>
                            <th>Destination</th><th>Vehicle</th><th>Rollbacks</th>
                            <th class="text-center">Stages back</th><th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $req):
                            $targets = rollbackTripTargets($req);
                            $stage = rollbackTripCurrentStage($req);
                            ?>
                            <tr>
                                <td><a href="<?= APP_URL ?>/?page=requests&action=view&id=<?= $req->id ?>"><strong>#<?= $req->id ?></strong></a></td>
                                <td><span class="badge bg-primary bg-opacity-75"><?= e(rollbackStageLabel($stage)) ?></span></td>
                                <td><?= requestStatusBadge($req->status) ?></td>
                                <td class="text-nowrap"><?= formatDateTime($req->start_datetime) ?></td>
                                <td><?= e($req->requester_name) ?><small class="d-block text-muted"><?= e($req->department_name) ?></small></td>
                                <td><?= e($req->destination) ?></td>
                                <td><?= $req->plate_number ? e($req->plate_number) : '-' ?></td>
                                <td><?php if ((int) $req->rollback_count > 0): ?><span class="badge bg-warning text-dark"><?= (int) $req->rollback_count ?>x</span><?php else: ?><span class="text-muted">-</span><?php endif; ?></td>
                                <td class="text-center">
                                    <?php if ($targets === []): ?>
                                        <span class="text-muted">—</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark"><?= count($targets) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($targets === []): ?>
                                        <span class="text-muted small">no earlier stage</span>
                                    <?php else: ?>
                                        <a href="<?= APP_URL ?>/?page=rollback&action=process&id=<?= $req->id ?>"
                                           class="btn btn-sm btn-outline-warning"><i class="bi bi-arrow-counterclockwise me-1"></i>Rollback</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php elseif ($tab === 'ob'): ?>
            <?php if (empty($obRows)): ?>
                <div class="text-center py-5 text-muted"><i class="bi bi-check2-circle fs-1"></i><p class="mt-2">No roll-backable pass slips found.</p></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Slip no</th><th>Stage</th><th>Date</th><th>Employee</th><th>Purpose</th>
                            <th>Vehicle</th><th class="text-center">Stages back</th><th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($obRows as $o):
                            $targets = rollbackObTargets($o);
                            ?>
                            <tr>
                                <td><a href="<?= APP_URL ?>/?page=ob-requests&action=view&id=<?= $o->id ?>"><strong><?= e($o->pass_slip_no) ?></strong></a></td>
                                <td><span class="badge bg-<?= e(obStatusColor($o->status)) ?>"><?= e(rollbackStageLabel($o->status)) ?></span></td>
                                <td class="text-nowrap"><?= formatDate($o->ob_date) ?></td>
                                <td><?= e($o->employee_name) ?></td>
                                <td><?= e($o->purpose) ?></td>
                                <td><?= $o->plate_number ? e($o->plate_number) : ($o->uses_official_vehicle ? 'Official' : 'Private') ?></td>
                                <td class="text-center">
                                    <?php if ($targets === []): ?>
                                        <span class="text-muted">—</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark"><?= count($targets) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($targets === []): ?>
                                        <span class="text-muted small">no earlier stage</span>
                                    <?php else: ?>
                                        <a href="<?= APP_URL ?>/?page=ob-requests&action=rollback&id=<?= $o->id ?>"
                                           class="btn btn-sm btn-outline-warning"><i class="bi bi-arrow-counterclockwise me-1"></i>Rollback</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <?php if (empty($gasRows)): ?>
                <div class="text-center py-5 text-muted"><i class="bi bi-check2-circle fs-1"></i><p class="mt-2">No roll-backable gas vouchers found.</p></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Voucher</th><th>Stage</th><th>Request date</th><th>Requester</th><th>Driver / Vehicle</th>
                            <th>Cost</th><th class="text-center">Stages back</th><th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($gasRows as $g):
                            $targets = rollbackGasTargets($g);
                            ?>
                            <tr>
                                <td><a href="<?= APP_URL ?>/?page=gas-vouchers&action=view&id=<?= $g->id ?>"><strong><?= e($g->voucher_no) ?></strong></a></td>
                                <td><span class="badge bg-secondary"><?= e(rollbackStageLabel($g->status)) ?></span></td>
                                <td class="text-nowrap"><?= formatDate($g->request_date) ?></td>
                                <td><?= e($g->requester_name) ?></td>
                                <td><?= e($g->driver_name) ?> — <?= e($g->vehicle_plate) ?></td>
                                <td><?= $g->total_cost !== null ? '₱' . number_format((float) $g->total_cost, 2) : '—' ?></td>
                                <td class="text-center">
                                    <?php if ($targets === []): ?>
                                        <span class="text-muted">—</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark"><?= count($targets) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($targets === []): ?>
                                        <span class="text-muted small">no earlier stage</span>
                                    <?php else: ?>
                                        <a href="<?= APP_URL ?>/?page=gas-vouchers&action=rollback&id=<?= $g->id ?>"
                                           class="btn btn-sm btn-outline-warning"><i class="bi bi-arrow-counterclockwise me-1"></i>Rollback</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>