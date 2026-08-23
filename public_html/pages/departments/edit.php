<?php
/**
 * LOKA - Edit Department Page
 */

requireRole(ROLE_ADMIN);

$deptId = (int) get('id');
$dept = db()->fetch("SELECT * FROM departments WHERE id = ? AND deleted_at IS NULL", [$deptId]);
if (!$dept) redirectWith('/?page=departments', 'danger', 'Department not found.');

$errors = [];
$users = getEmployees(userId() ?: 0); // Use cached employees

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    
    $name = postSafe('name', '', 100);
    $description = postSafe('description', '', 500);
    $headUserId = postInt('head_user_id') ?: null;
    $status = postSafe('status', '', 20);
    
    if (empty($name)) $errors[] = 'Name is required';
    
    if ($name && $name !== $dept->name) {
        $existing = db()->fetch("SELECT id FROM departments WHERE name = ? AND id != ? AND deleted_at IS NULL", [$name, $deptId]);
        if ($existing) $errors[] = 'Department name already exists';
    }
    
    if (empty($errors)) {
        db()->update('departments', [
            'name' => $name,
            'description' => $description,
            'head_user_id' => $headUserId,
            'status' => $status,
            'updated_at' => date(DATETIME_FORMAT)
        ], 'id = ?', [$deptId]);

        auditLog('department_updated', 'department', $deptId);
        clearDepartmentCache(); // Clear department cache after updating department
        redirectWith('/?page=departments', 'success', 'Department updated successfully.');
    }
}

$pageTitle = 'Edit Department';
require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="mb-4">
        <h4 class="mb-1">Edit Department: <?= e($dept->name) ?></h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0"><li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li><li class="breadcrumb-item"><a href="<?= APP_URL ?>/?page=departments">Departments</a></li><li class="breadcrumb-item active">Edit</li></ol></nav>
    </div>
    
    <div class="row">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><h5 class="mb-0"><i class="bi bi-pencil me-2"></i>Edit Department</h5></div>
                <div class="card-body">
                    <?php if (!empty($errors)): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
                    
                    <form method="POST">
                        <?= csrfField() ?>
                        <div class="mb-3">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" value="<?= e(post('name', $dept->name)) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="3"><?= e(post('description', $dept->description)) ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Department Head</label>
                            <select class="form-select" name="head_user_id">
                                <option value="">Select head...</option>
                                <?php foreach ($users as $user): ?>
                                <option value="<?= $user->id ?>" <?= post('head_user_id', $dept->head_user_id) == $user->id ? 'selected' : '' ?>><?= e($user->name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="active" <?= post('status', $dept->status) === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= post('status', $dept->status) === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                        <hr class="my-4">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Changes</button>
                        <a href="<?= APP_URL ?>/?page=departments" class="btn btn-outline-secondary">Cancel</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
