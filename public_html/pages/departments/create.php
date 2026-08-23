<?php
/**
 * LOKA - Create Department Page
 */

requireRole(ROLE_ADMIN);

$pageTitle = 'Add Department';
$errors = [];

$users = getEmployees(userId() ?: 0); // Use cached employees, exclude current user (but none for this page)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    
    $name = postSafe('name', '', 100);
    $description = postSafe('description', '', 500);
    $headUserId = postInt('head_user_id') ?: null;
    
    if (empty($name)) $errors[] = 'Name is required';
    
    if ($name) {
        $existing = db()->fetch("SELECT id FROM departments WHERE name = ? AND deleted_at IS NULL", [$name]);
        if ($existing) $errors[] = 'Department name already exists';
    }
    
    if (empty($errors)) {
        $deptId = db()->insert('departments', [
            'name' => $name,
            'description' => $description,
            'head_user_id' => $headUserId,
            'status' => 'active',
            'created_at' => date(DATETIME_FORMAT),
            'updated_at' => date(DATETIME_FORMAT)
        ]);

        auditLog('department_created', 'department', $deptId);
        clearDepartmentCache(); // Clear department cache after creating department
        redirectWith('/?page=departments', 'success', 'Department created successfully.');
    }
}

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="mb-4">
        <h4 class="mb-1">Add Department</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0"><li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li><li class="breadcrumb-item"><a href="<?= APP_URL ?>/?page=departments">Departments</a></li><li class="breadcrumb-item active">Add</li></ol></nav>
    </div>
    
    <div class="row">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><h5 class="mb-0"><i class="bi bi-building me-2"></i>Department Details</h5></div>
                <div class="card-body">
                    <?php if (!empty($errors)): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
                    
                    <form method="POST">
                        <?= csrfField() ?>
                        <div class="mb-3">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" value="<?= e(post('name', '')) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="3"><?= e(post('description', '')) ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Department Head</label>
                            <select class="form-select" name="head_user_id">
                                <option value="">Select head...</option>
                                <?php foreach ($users as $user): ?>
                                <option value="<?= $user->id ?>" <?= post('head_user_id') == $user->id ? 'selected' : '' ?>><?= e($user->name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <hr class="my-4">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Create Department</button>
                        <a href="<?= APP_URL ?>/?page=departments" class="btn btn-outline-secondary">Cancel</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
