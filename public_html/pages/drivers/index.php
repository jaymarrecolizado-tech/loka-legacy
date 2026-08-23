<?php
/**
 * LOKA - Drivers List Page
 */

requireRole(ROLE_APPROVER);

$pageTitle = 'Drivers';
$statusFilter = get('status', '');

$params = [];
$whereClause = 'd.deleted_at IS NULL';

if ($statusFilter) {
    $whereClause .= ' AND d.status = ?';
    $params[] = $statusFilter;
}

$drivers = db()->fetchAll(
    "SELECT d.*, u.name as driver_name, u.email, u.phone
     FROM drivers d
     JOIN users u ON d.user_id = u.id AND u.deleted_at IS NULL
     WHERE {$whereClause}
     ORDER BY d.id DESC",
    $params
);

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1">Drivers</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
                    <li class="breadcrumb-item active">Drivers</li>
                </ol>
            </nav>
        </div>
        <?php if (isApprover()): ?>
            <a href="<?= APP_URL ?>/?page=drivers&action=create" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i>Add Driver
            </a>
        <?php endif; ?>
    </div>

    <!-- Filters -->
    <div class="card table-card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="page" value="drivers">
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Statuses</option>
                        <?php foreach (DRIVER_STATUS_LABELS as $key => $info): ?>
                            <option value="<?= $key ?>" <?= $statusFilter === $key ? 'selected' : '' ?>>
                                <?= e($info['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-outline-primary me-2"><i
                            class="bi bi-search me-1"></i>Filter</button>
                    <a href="<?= APP_URL ?>/?page=drivers" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Drivers Table -->
    <div class="card table-card">
        <div class="card-body">
            <?php if (empty($drivers)): ?>
                <div class="empty-state">
                    <i class="bi bi-person-badge"></i>
                    <h5>No drivers found</h5>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover data-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>License #</th>
                                <th>License Expiry</th>
                                <th>Experience</th>
                                <th>Status</th>
                                <th>Contact</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($drivers as $driver): ?>
                                <tr>
                                    <td><strong><?= e($driver->driver_name) ?></strong></td>
                                    <td><?= e($driver->license_number) ?></td>
                                    <td>
                                        <?php
                                        $expiry = strtotime($driver->license_expiry);
                                        $daysUntil = ($expiry - time()) / 86400;
                                        $expiryClass = $daysUntil < 30 ? 'text-danger' : ($daysUntil < 90 ? 'text-warning' : '');
                                        ?>
                                        <span class="<?= $expiryClass ?>"><?= formatDate($driver->license_expiry) ?></span>
                                    </td>
                                    <td><?= $driver->years_experience ?> years</td>
                                    <td><?= driverStatusBadge($driver->status) ?></td>
                                    <td><small><?= e($driver->phone ?: '-') ?></small></td>
                                    <td>
                                        <div class="btn-group">
                                            <?php if (isApprover()): ?>
                                                <a href="<?= APP_URL ?>/?page=drivers&action=edit&id=<?= $driver->id ?>"
                                                    class="btn btn-sm btn-outline-secondary" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <form method="POST" action="<?= APP_URL ?>/?page=drivers&action=delete"
                                                    style="display:inline;">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="id" value="<?= $driver->id ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"
                                                        data-confirm="Delete this driver?">
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