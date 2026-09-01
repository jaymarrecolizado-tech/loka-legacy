<?php
/**
 * LOKA - Vehicles List Page
 */

requireRole(ROLE_APPROVER);

$pageTitle = 'Vehicles';

// Get filters
$statusFilter = get('status', '');
$typeFilter = get('type', '');
$searchFilter = trim(get('search', ''));

// Build query
$params = [];
$whereClause = 'v.deleted_at IS NULL';

if ($statusFilter) {
    $whereClause .= ' AND v.status = ?';
    $params[] = $statusFilter;
}

if ($typeFilter) {
    $whereClause .= ' AND v.vehicle_type_id = ?';
    $params[] = $typeFilter;
}

if ($searchFilter !== '') {
    $whereClause .= ' AND (v.plate_number LIKE ? OR v.make LIKE ? OR v.model LIKE ? OR vt.name LIKE ?)';
    $like = '%' . $searchFilter . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$vehicles = db()->fetchAll(
    "SELECT v.*, vt.name as type_name, vt.passenger_capacity
     FROM vehicles v
     JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
     WHERE {$whereClause}
     ORDER BY v.id DESC",
    $params
);

$vehicleTypes = db()->fetchAll("SELECT * FROM vehicle_types WHERE deleted_at IS NULL ORDER BY name");

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1">Vehicles</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
                    <li class="breadcrumb-item active">Vehicles</li>
                </ol>
            </nav>
        </div>
        <?php if (isApprover()): ?>
            <a href="<?= APP_URL ?>/?page=vehicles&action=create" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i>Add Vehicle
            </a>
        <?php endif; ?>
    </div>

    <!-- Filters -->
    <div class="card table-card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="page" value="vehicles">

                <div class="col-md-3">
                    <label class="form-label">Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Plate, make, model, type..." value="<?= e($searchFilter) ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Statuses</option>
                        <?php foreach (VEHICLE_STATUS_LABELS as $key => $info): ?>
                            <option value="<?= $key ?>" <?= $statusFilter === $key ? 'selected' : '' ?>>
                                <?= e($info['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Type</label>
                    <select name="type" class="form-select">
                        <option value="">All Types</option>
                        <?php foreach ($vehicleTypes as $type): ?>
                            <option value="<?= $type->id ?>" <?= $typeFilter == $type->id ? 'selected' : '' ?>>
                                <?= e($type->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <button type="submit" class="btn btn-outline-primary me-2"><i
                            class="bi bi-search me-1"></i>Filter</button>
                    <a href="<?= APP_URL ?>/?page=vehicles" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Vehicles Table -->
    <div class="card table-card">
        <div class="card-body">
            <?php if (empty($vehicles)): ?>
                <div class="empty-state">
                    <i class="bi bi-car-front"></i>
                    <h5>No vehicles found</h5>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover data-table">
                        <thead>
                            <tr>
                                <th>Plate #</th>
                                <th>Vehicle</th>
                                <th>Type</th>
                                <th class="text-center">Capacity</th>
                                <th>Status</th>
                                <th class="text-end">Mileage</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vehicles as $vehicle): ?>
                                <tr>
                                    <td><strong><?= e($vehicle->plate_number) ?></strong></td>
                                    <td>
                                        <?= e($vehicle->make . ' ' . $vehicle->model) ?>
                                        <small class="d-block text-muted"><?= e($vehicle->year) ?> •
                                            <?= e($vehicle->color) ?></small>
                                    </td>
                                    <td><span class="badge bg-light text-dark"><?= e($vehicle->type_name) ?></span></td>
                                    <td class="text-center"><?= $vehicle->passenger_capacity ?></td>
                                    <td><?= vehicleStatusBadge($vehicle->status) ?></td>
                                    <td class="text-end text-nowrap"><?= number_format($vehicle->mileage) ?> km</td>
                                     <td>
                                        <div class="btn-group">
                                            <a href="<?= APP_URL ?>/?page=vehicles&action=view&id=<?= $vehicle->id ?>"
                                                class="btn btn-sm btn-outline-primary" title="View">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <?php if (isApprover()): ?>
                                                <a href="<?= APP_URL ?>/?page=vehicles&action=edit&id=<?= $vehicle->id ?>"
                                                    class="btn btn-sm btn-outline-secondary" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <form method="POST" action="<?= APP_URL ?>/?page=vehicles&action=delete" class="d-inline">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="id" value="<?= $vehicle->id ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"
                                                        data-confirm="Delete this vehicle?">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
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