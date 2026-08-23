<?php
/**
 * LOKA - Users List Page
 */

requireRole(ROLE_MOTORPOOL);

$pageTitle = 'Users';
$roleFilter = get('role', '');
$statusFilter = get('status', '');
$searchQuery = trim(get('search', ''));

// Pagination
$perPage = 15;
$currentPage = max(1, (int) get('p', 1));
$offset = ($currentPage - 1) * $perPage;

$params = [];
$whereClause = 'deleted_at IS NULL';
$whereClauseAliased = 'u.deleted_at IS NULL';

if ($roleFilter) {
    $whereClause .= ' AND role = ?';
    $whereClauseAliased .= ' AND u.role = ?';
    $params[] = $roleFilter;
}

if ($statusFilter) {
    $whereClause .= ' AND status = ?';
    $whereClauseAliased .= ' AND u.status = ?';
    $params[] = $statusFilter;
}

if ($searchQuery) {
    $whereClause .= ' AND (name LIKE ? OR email LIKE ?)';
    $whereClauseAliased .= ' AND (u.name LIKE ? OR u.email LIKE ?)';
    $searchPattern = '%' . $searchQuery . '%';
    $params[] = $searchPattern;
    $params[] = $searchPattern;
}

// Get total count for pagination
$totalUsers = db()->count('users', $whereClause, $params);
$totalPages = ceil($totalUsers / $perPage);

// Get paginated users
$users = db()->fetchAll(
    "SELECT u.*, d.name as department_name
     FROM users u
     LEFT JOIN departments d ON u.department_id = d.id
     WHERE {$whereClauseAliased}
     ORDER BY u.id DESC
     LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $offset])
);

$departments = db()->fetchAll("SELECT * FROM departments WHERE departments.deleted_at IS NULL ORDER BY name");

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1">Users</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0"><li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li><li class="breadcrumb-item active">Users</li></ol></nav>
        </div>
        <?php if (isAdmin()): ?>
        <a href="<?= APP_URL ?>/?page=users&action=create" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Add User</a>
        <?php endif; ?>
    </div>
    
    <div class="card table-card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="page" value="users">
                <div class="col-md-3">
                    <label class="form-label">Search (Name/Email)</label>
                    <input type="text" name="search" class="form-control" placeholder="Search by name or email..." value="<?= e($searchQuery) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Role</label>
                    <select name="role" class="form-select">
                        <option value="">All Roles</option>
                        <?php foreach (ROLE_LABELS as $key => $info): ?>
                        <option value="<?= $key ?>" <?= $roleFilter === $key ? 'selected' : '' ?>><?= e($info['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Statuses</option>
                        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-outline-primary me-2"><i class="bi bi-search me-1"></i>Filter</button>
                    <a href="<?= APP_URL ?>/?page=users" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>
    
    <div class="card table-card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover data-table">
                    <thead>
                        <tr><th>Name</th><th>Email</th><th>Department</th><th>Role</th><th>Status</th><th>Last Login</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td><strong><?= e($user->name) ?></strong></td>
                            <td><?= e($user->email) ?></td>
                            <td><span class="badge bg-light text-dark"><?= e($user->department_name ?: 'None') ?></span></td>
                            <td><?= roleBadge($user->role) ?></td>
                            <td><span class="badge bg-<?= $user->status === 'active' ? 'success' : 'secondary' ?>"><?= ucfirst($user->status) ?></span></td>
                            <td><?= $user->last_login_at ? formatDateTime($user->last_login_at) : 'Never' ?></td>
                            <td>
                                <?php if (isAdmin()): ?>
                                <div class="btn-group">
                                    <a href="<?= APP_URL ?>/?page=users&action=edit&id=<?= $user->id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                                    <a href="<?= APP_URL ?>/?page=users&action=toggle&id=<?= $user->id ?>" class="btn btn-sm btn-outline-<?= $user->status === 'active' ? 'warning' : 'success' ?>" data-confirm="<?= $user->status === 'active' ? 'Deactivate' : 'Activate' ?> this user?">
                                        <i class="bi bi-<?= $user->status === 'active' ? 'x-circle' : 'check-circle' ?>"></i>
                                    </a>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if ($totalPages > 1): ?>
            <div class="card-footer bg-transparent border-top">
                <nav aria-label="Users pagination">
                    <ul class="pagination pagination-sm justify-content-center mb-0">
                        <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= APP_URL ?>/?page=users&p=<?= $currentPage - 1 ?><?= $roleFilter ? '&role=' . $roleFilter : '' ?><?= $statusFilter ? '&status=' . $statusFilter : '' ?><?= $searchQuery ? '&search=' . urlencode($searchQuery) : '' ?>">&laquo;</a>
                        </li>
                        
                        <?php
                        $start = max(1, $currentPage - 2);
                        $end = min($totalPages, $currentPage + 2);
                        
                        if ($start > 1): ?>
                        <li class="page-item"><a class="page-link" href="<?= APP_URL ?>/?page=users&p=1<?= $roleFilter ? '&role=' . $roleFilter : '' ?><?= $statusFilter ? '&status=' . $statusFilter : '' ?><?= $searchQuery ? '&search=' . urlencode($searchQuery) : '' ?>">1</a></li>
                        <?php if ($start > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php for ($i = $start; $i <= $end; $i++): ?>
                        <li class="page-item <?= $i === $currentPage ? 'active' : '' ?>">
                            <a class="page-link" href="<?= APP_URL ?>/?page=users&p=<?= $i ?><?= $roleFilter ? '&role=' . $roleFilter : '' ?><?= $statusFilter ? '&status=' . $statusFilter : '' ?><?= $searchQuery ? '&search=' . urlencode($searchQuery) : '' ?>"><?= $i ?></a>
                        </li>
                        <?php endfor; ?>
                        
                        <?php if ($end < $totalPages): ?>
                        <?php if ($end < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                        <li class="page-item"><a class="page-link" href="<?= APP_URL ?>/?page=users&p=<?= $totalPages ?><?= $roleFilter ? '&role=' . $roleFilter : '' ?><?= $statusFilter ? '&status=' . $statusFilter : '' ?><?= $searchQuery ? '&search=' . urlencode($searchQuery) : '' ?>"><?= $totalPages ?></a></li>
                        <?php endif; ?>
                        
                        <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= APP_URL ?>/?page=users&p=<?= $currentPage + 1 ?><?= $roleFilter ? '&role=' . $roleFilter : '' ?><?= $statusFilter ? '&status=' . $statusFilter : '' ?><?= $searchQuery ? '&search=' . urlencode($searchQuery) : '' ?>">&raquo;</a>
                        </li>
                    </ul>
                </nav>
                <div class="text-center mt-2">
                    <small class="text-muted">Page <?= $currentPage ?> of <?= $totalPages ?> (<?= $totalUsers ?> total users)</small>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
