<?php
/**
 * LOKA - Request Rollback Hub (Admin + Guard)
 *
 * Admins: lists all requests eligible for workflow rollback.
 * Guards: restricted to approved requests (rollback -> pending_motorpool only).
 */

requireAuth();
if (!isAdmin() && !isGuard()) {
    redirectWith('/?page=dashboard', 'danger', 'You do not have permission to access this page.');
}

$isAdminUser = isAdmin();
$pageTitle = 'Request Rollback';

$startDate = get('start_date', '');
$endDate = get('end_date', '');
$filterStatus = get('status', '');
$filterDept = get('department_id', '');
$search = trim(get('search', ''));

$rollbackableStatuses = $isAdminUser
    ? [STATUS_PENDING_MOTORPOOL, STATUS_APPROVED, STATUS_COMPLETED, STATUS_REVISION, STATUS_REJECTED]
    : [STATUS_APPROVED];

$allDepartments = db()->fetchAll("SELECT id, name FROM departments WHERE deleted_at IS NULL ORDER BY name");

// Summary counts per status
$summary = [];
$statusListSql = implode("','", $rollbackableStatuses);
foreach (db()->fetchAll(
    "SELECT status, COUNT(*) AS cnt FROM requests
     WHERE deleted_at IS NULL AND status IN ('$statusListSql')
     GROUP BY status"
) as $row) {
    $summary[$row->status] = (int)$row->cnt;
}

// Build list query
$where = ['r.deleted_at IS NULL', "r.status IN ('$statusListSql')"];
$params = [];

if ($filterStatus && in_array($filterStatus, $rollbackableStatuses)) {
    $where[] = "r.status = ?";
    $params[] = $filterStatus;
}
if ($filterDept) {
    $where[] = "r.department_id = ?";
    $params[] = $filterDept;
}
if ($search !== '') {
    $where[] = "(r.destination LIKE ? OR r.purpose LIKE ? OR u.name LIKE ? OR v.plate_number LIKE ?)";
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term);
}
if ($startDate) {
    $where[] = "r.start_datetime >= ?";
    $params[] = $startDate;
}
if ($endDate) {
    $where[] = "r.start_datetime <= ?";
    $params[] = $endDate . ' 23:59:59';
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

$requests = db()->fetchAll(
    "SELECT r.id, r.status, r.start_datetime, r.destination, r.purpose, r.rollback_count,
            r.created_at, r.updated_at,
            u.name as requester_name, dept.name as department_name,
            v.plate_number, v.make, v.model,
            du.name as driver_name
     FROM requests r
     JOIN users u ON r.user_id = u.id
     LEFT JOIN departments dept ON r.department_id = dept.id
     LEFT JOIN vehicles v ON r.vehicle_id = v.id
     LEFT JOIN drivers d ON r.driver_id = d.id
     LEFT JOIN users du ON d.user_id = du.id
     $whereClause
     ORDER BY r.updated_at DESC
     LIMIT 500",
    $params
);

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="mb-4">
        <h4 class="mb-1"><i class="bi bi-arrow-counterclockwise me-2"></i>Request Rollback</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
                <li class="breadcrumb-item active">Request Rollback</li>
            </ol>
        </nav>
    </div>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
        <?php
        $allCards = [
            STATUS_PENDING_MOTORPOOL => ['Pending Motorpool', 'info'],
            STATUS_APPROVED          => ['Approved', 'success'],
            STATUS_COMPLETED         => ['Completed', 'primary'],
            STATUS_REVISION          => ['For Revision', 'orange'],
            STATUS_REJECTED          => ['Rejected', 'danger'],
        ];
        $cards = array_filter($allCards, fn($st) => in_array($st, $rollbackableStatuses), ARRAY_FILTER_USE_KEY);
        foreach ($cards as $st => [$label, $color]):
            $url = '?page=rollback&status=' . $st;
        ?>
        <div class="col">
            <a href="<?= $url ?>" class="text-decoration-none">
                <div class="card bg-<?= $color === 'orange' ? 'warning' : $color ?> bg-opacity-10">
                    <div class="card-body text-center py-2">
                        <h4 class="text-<?= $color === 'orange' ? 'warning' : $color ?> mb-0"><?= $summary[$st] ?? 0 ?></h4>
                        <small class="text-muted"><?= $label ?></small>
                    </div>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="page" value="rollback">
                <div class="col-md-3">
                    <label class="form-label">Search</label>
                    <input type="text" class="form-control" name="search" value="<?= e($search) ?>"
                        placeholder="Destination, purpose, requester, plate...">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="">All Roll-backable</option>
                        <?php foreach ($cards as $st => [$label, $color]): ?>
                        <option value="<?= $st ?>" <?= $filterStatus === $st ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Department</label>
                    <select class="form-select" name="department_id">
                        <option value="">All Departments</option>
                        <?php foreach ($allDepartments as $dept): ?>
                        <option value="<?= $dept->id ?>" <?= $filterDept == $dept->id ? 'selected' : '' ?>><?= e($dept->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">From</label>
                    <input type="date" class="form-control" name="start_date" value="<?= e($startDate) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">To</label>
                    <input type="date" class="form-control" name="end_date" value="<?= e($endDate) ?>">
                </div>
                <div class="col-md-1 d-grid">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Requests Table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Roll-backable Requests</h5>
            <small class="text-muted"><?= count($requests) ?> record(s)</small>
        </div>
        <div class="card-body p-0">
            <?php if (empty($requests)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-check2-circle fs-1"></i>
                <p class="mt-2">No roll-backable requests found.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Status</th>
                            <th>Scheduled</th>
                            <th>Requester</th>
                            <th>Destination</th>
                            <th>Vehicle</th>
                            <th>Driver</th>
                            <th>Rollbacks</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $req): ?>
                        <tr>
                            <td>
                                <a href="<?= APP_URL ?>/?page=requests&action=view&id=<?= $req->id ?>">
                                    <strong>#<?= $req->id ?></strong>
                                </a>
                            </td>
                            <td><?= requestStatusBadge($req->status) ?></td>
                            <td><?= formatDateTime($req->start_datetime) ?></td>
                            <td>
                                <?= e($req->requester_name) ?>
                                <small class="d-block text-muted"><?= e($req->department_name) ?></small>
                            </td>
                            <td><?= e($req->destination) ?></td>
                            <td><?= $req->plate_number ? e($req->plate_number) : '-' ?></td>
                            <td><?= $req->driver_name ? e($req->driver_name) : '-' ?></td>
                            <td>
                                <?php if ((int)$req->rollback_count > 0): ?>
                                <span class="badge bg-warning text-dark"><?= (int)$req->rollback_count ?>x</span>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?= APP_URL ?>/?page=rollback&action=process&id=<?= $req->id ?>"
                                   class="btn btn-sm btn-outline-warning" title="Roll back this request">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i>Rollback
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
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
