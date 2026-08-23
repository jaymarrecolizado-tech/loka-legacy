<?php
/**
 * LOKA - My Trip Tickets Page
 *
 * Dedicated page for approvers to view trip tickets
 */

// Only approvers can access this page
if (!isApprover()) {
    redirectWith('/?page=dashboard', 'danger', 'This page is only for approvers.');
}

$pageTitle = 'My Trip Tickets';

// Get filter and search parameters
$statusFilter = get('status', '');
$search = get('search', '');

// Build query for trip tickets
$sql = "SELECT tt.*,
            r.id as request_id, r.destination as trip_destination, r.purpose as trip_purpose,
            r.status as request_status,
            v.plate_number, v.make, v.model as vehicle_model,
            dg.name as dispatch_guard,
            ag.name as arrival_guard,
            u_rev.name as reviewed_by_name
     FROM trip_tickets tt
     JOIN requests r ON tt.request_id = r.id
     LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
     LEFT JOIN users dg ON tt.dispatch_guard_id = dg.id
     LEFT JOIN users ag ON tt.arrival_guard_id = ag.id
     LEFT JOIN users u_rev ON tt.reviewed_by = u_rev.id
     WHERE tt.deleted_at IS NULL";

$params = [];

// Apply status filter
if ($statusFilter && in_array($statusFilter, ['draft', 'submitted', 'reviewed', 'approved'])) {
    $sql .= " AND tt.status = ?";
    $params[] = $statusFilter;
}

// Apply search
if ($search) {
    $sql .= " AND (
        r.destination LIKE ? OR
        r.purpose LIKE ? OR
        tt.issues_description LIKE ?
    )";
    $searchParam = '%' . $search . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$sql .= " ORDER BY tt.created_at DESC";

$tripTickets = db()->fetchAll($sql, $params);

// Get counts for each status
$stats = [
    'all' => db()->fetchColumn("SELECT COUNT(*) FROM trip_tickets WHERE deleted_at IS NULL"),
    'draft' => db()->fetchColumn("SELECT COUNT(*) FROM trip_tickets WHERE status = 'draft' AND deleted_at IS NULL"),
    'submitted' => db()->fetchColumn("SELECT COUNT(*) FROM trip_tickets WHERE status = 'submitted' AND deleted_at IS NULL"),
    'reviewed' => db()->fetchColumn("SELECT COUNT(*) FROM trip_tickets WHERE status = 'reviewed' AND deleted_at IS NULL"),
    'approved' => db()->fetchColumn("SELECT COUNT(*) FROM trip_tickets WHERE status = 'approved' AND deleted_at IS NULL"),
];

// Approvers don't create tickets, so no completed trips section

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-file-earmark-text me-2"></i>My Trip Tickets</h4>
            <p class="text-muted mb-0">View and manage trip tickets</p>
        </div>
        <div class="d-flex gap-2">
            <a href="?page=my-trip-tickets&action=generate-summary" class="btn btn-outline-success">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i>Generate Vehicle Summary
            </a>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-primary bg-opacity-10 rounded p-3">
                                <i class="bi bi-files text-primary fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">All Tickets</h6>
                            <h3 class="mb-0"><?= $stats['all'] ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-warning bg-opacity-10 rounded p-3">
                                <i class="bi bi-clock text-warning fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Pending Review</h6>
                            <h3 class="mb-0"><?= $stats['submitted'] ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-info bg-opacity-10 rounded p-3">
                                <i class="bi bi-arrow-counterclockwise text-info fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Reviewed</h6>
                            <h3 class="mb-0"><?= $stats['reviewed'] ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-success bg-opacity-10 rounded p-3">
                                <i class="bi bi-check-circle text-success fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Approved</h6>
                            <h3 class="mb-0"><?= $stats['approved'] ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>



    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Status Filter</label>
                    <div class="btn-group w-100" role="group">
                        <a href="?page=my-trip-tickets"
                            class="btn btn-outline-secondary <?= $statusFilter === '' ? 'active' : '' ?>">
                            All (<?= $stats['all'] ?>)
                        </a>
                        <a href="?page=my-trip-tickets&status=submitted"
                            class="btn btn-outline-warning <?= $statusFilter === 'submitted' ? 'active' : '' ?>">
                            Pending (<?= $stats['submitted'] ?>)
                        </a>
                        <a href="?page=my-trip-tickets&status=reviewed"
                            class="btn btn-outline-info <?= $statusFilter === 'reviewed' ? 'active' : '' ?>">
                            Reviewed (<?= $stats['reviewed'] ?>)
                        </a>
                        <a href="?page=my-trip-tickets&status=approved"
                            class="btn btn-outline-success <?= $statusFilter === 'approved' ? 'active' : '' ?>">
                            Approved (<?= $stats['approved'] ?>)
                        </a>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Search</label>
                    <form method="GET" class="d-flex gap-2">
                        <input type="hidden" name="page" value="my-trip-tickets">
                        <input type="text" name="search" class="form-control"
                            placeholder="Search destination or purpose..." value="<?= e($search) ?>">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i>
                        </button>
                        <?php if ($search): ?>
                            <a href="?page=my-trip-tickets" class="btn btn-outline-secondary">
                                <i class="bi bi-x-lg"></i>
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Trip Tickets List -->
    <div class="card">
        <div class="card-header bg-white">
            <h5 class="mb-0">
                <i class="bi bi-list-ul me-2"></i>
                My Trip Tickets
            </h5>
        </div>
        <div class="card-body">
            <?php if (empty($tripTickets)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox fs-1 text-muted"></i>
                    <p class="text-muted mt-3">
                        <?php if ($statusFilter): ?>
                            No trip tickets found with status "<?= ucfirst($statusFilter) ?>"
                        <?php elseif ($search): ?>
                            No trip tickets found matching "<?= e($search) ?>"
                        <?php else: ?>
                            You haven't created any trip tickets yet.
                            <br>
                            <a href="?page=my-trips" class="btn btn-primary mt-2">
                                <i class="bi bi-calendar3 me-1"></i>View My Trips
                            </a>
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Ticket ID</th>
                                <th>Destination</th>
                                <th>Date</th>
                                <th>Vehicle</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tripTickets as $tt): ?>
                                <?php
                                $statusClass = '';
                                $statusIcon = '';
                                switch ($tt->status) {
                                    case 'draft':
                                        $statusClass = 'secondary';
                                        $statusIcon = 'file-earmark';
                                        break;
                                    case 'submitted':
                                        $statusClass = 'warning';
                                        $statusIcon = 'clock';
                                        break;
                                    case 'reviewed':
                                        $statusClass = 'info';
                                        $statusIcon = 'arrow-counterclockwise';
                                        break;
                                    case 'approved':
                                        $statusClass = 'success';
                                        $statusIcon = 'check-circle';
                                        break;
                                }

                                $tripTypeLabels = [
                                    'official' => ['label' => 'Official', 'color' => 'success'],
                                    'personal' => ['label' => 'Personal', 'color' => 'info'],
                                    'maintenance' => ['label' => 'Maintenance', 'color' => 'warning'],
                                    'travel_order' => ['label' => 'Travel Order', 'color' => 'primary'],
                                    'other' => ['label' => 'Other', 'color' => 'secondary']
                                ];
                                $typeInfo = $tripTypeLabels[$tt->trip_type] ?? $tripTypeLabels['official'];
                                // Use custom label for "Other" type
                                if ($tt->trip_type === 'other' && !empty($tt->trip_type_other)) {
                                    $typeInfo['label'] = e($tt->trip_type_other);
                                }
                                ?>
                                <tr>
                                    <td>
                                        <strong>TT-<?= $tt->request_id ?></strong>
                                        <br>
                                        <small class="text-muted">(Ref: VRF-<?= $tt->request_id ?>)</small>
                                    </td>
                                    <td>
                                        <div class="fw-medium"><?= e($tt->trip_destination) ?></div>
                                        <small class="text-muted"><?= truncate($tt->trip_purpose, 40) ?></small>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <i class="bi bi-calendar3 me-1"></i>
                                            <?= formatDate($tt->start_date, 'M/d/Y') ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-medium"><?= $tt->plate_number ?: 'N/A' ?></div>
                                        <small class="text-muted"><?= $tt->make ?>         <?= $tt->vehicle_model ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $statusClass ?> me-1">
                                            <i class="bi bi-<?= $statusIcon ?> me-1"></i>
                                            <?= ucfirst($tt->status) ?>
                                        </span>
                                        <span class="badge bg-<?= $typeInfo['color'] ?>">
                                            <?= $typeInfo['label'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="?page=trip-tickets&action=view&id=<?= $tt->id ?>"
                                                class="btn btn-outline-primary" title="View Ticket">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="?page=trip-tickets&action=export-pdf&id=<?= $tt->id ?>"
                                                class="btn btn-outline-danger" title="Export PDF">
                                                <i class="bi bi-file-earmark-pdf"></i>
                                            </a>
                                            <a href="?page=trip-tickets&action=export-excel&id=<?= $tt->id ?>"
                                                class="btn btn-outline-success" title="Export Excel">
                                                <i class="bi bi-file-earmark-excel"></i>
                                            </a>
                                        </div>
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