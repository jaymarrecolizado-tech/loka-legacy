<?php
/**
 * LOKA - Departments List Page
 */

requireRole(ROLE_MOTORPOOL);

$pageTitle = 'Departments';

$departments = db()->fetchAll(
    "SELECT d.*, u.name as head_name,
            (SELECT COUNT(*) FROM users WHERE department_id = d.id AND deleted_at IS NULL) as user_count
     FROM departments d
     LEFT JOIN users u ON d.head_user_id = u.id
     WHERE d.deleted_at IS NULL
     ORDER BY d.name"
);

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1">Departments</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0"><li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li><li class="breadcrumb-item active">Departments</li></ol></nav>
        </div>
        <?php if (isAdmin()): ?>
        <a href="<?= APP_URL ?>/?page=departments&action=create" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Department</a>
        <?php endif; ?>
    </div>
    
    <div class="card table-card">
        <div class="card-body">
            <?php if (empty($departments)): ?>
            <div class="empty-state"><i class="bi bi-building"></i><h5>No departments found</h5></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover data-table">
                    <thead><tr><th>Name</th><th>Description</th><th>Head</th><th>Users</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($departments as $dept): ?>
                        <tr>
                            <td><strong><?= e($dept->name) ?></strong></td>
                            <td><?= truncate($dept->description ?: '-', 40) ?></td>
                            <td><?= e($dept->head_name ?: '-') ?></td>
                            <td><span class="badge bg-light text-dark"><?= $dept->user_count ?></span></td>
                            <td><span class="badge bg-<?= $dept->status === 'active' ? 'success' : 'secondary' ?>"><?= ucfirst($dept->status) ?></span></td>
                            <td>
                                <?php if (isAdmin()): ?>
                                <a href="<?= APP_URL ?>/?page=departments&action=edit&id=<?= $dept->id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                                <?php endif; ?>
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
