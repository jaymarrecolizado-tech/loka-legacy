<?php
/**
 * LOKA - Edit User Page
 */

requireRole(ROLE_ADMIN);

$userId = (int) get('id');
$user = db()->fetch("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL FOR UPDATE", [$userId]);
if (!$user)
    redirectWith('/?page=users', 'danger', 'User not found.');

$errors = [];
$departments = getDepartments(); // Use cached departments

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $name = postSafe('name', '', 100);
    $email = post('email');
    $password = post('password');
    $phone = postSafe('phone', '', 20);
    $role = postSafe('role', '', 20);
    $departmentId = postInt('department_id') ?: null;

    if (empty($name))
        $errors[] = 'Name is required';
    if (empty($email))
        $errors[] = 'Email is required';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors[] = 'Invalid email format';
    if ($password && strlen($password) < 8)
        $errors[] = 'Password must be at least 8 characters';

    if (empty($errors)) {
        db()->beginTransaction();

        try {
            // Re-fetch with lock to ensure atomicity
            $user = db()->fetch("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL FOR UPDATE", [$userId]);

            // Check unique email (exclude current)
            if ($email && $email !== $user->email) {
                $existing = db()->fetch("SELECT id FROM users WHERE email = ? AND id != ? AND deleted_at IS NULL", [$email, $userId]);
                if ($existing) {
                    db()->rollback();
                    $errors[] = 'Email already exists';
                }
            }

            if (empty($errors)) {
                $updateData = [
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'role' => $role,
                    'department_id' => $departmentId,
                    'updated_at' => date(DATETIME_FORMAT)
                ];

                if ($password) {
                    $auth = new Auth();
                    $updateData['password'] = $auth->hashPassword($password);
                }

                db()->update('users', $updateData, 'id = ?', [$userId]);
                auditLog('user_updated', 'user', $userId);
                db()->commit();
                clearUserCache(); // Clear user cache after updating user
                redirectWith('/?page=users', 'success', 'User updated successfully.');
            }
        } catch (Exception $e) {
            db()->rollback();
            $errors[] = 'Failed to update user';
        }
    }
}

$pageTitle = 'Edit User';
require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="mb-4">
        <h4 class="mb-1">Edit User: <?= e($user->name) ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>/?page=users">Users</a></li>
                <li class="breadcrumb-item active">Edit</li>
            </ol>
        </nav>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-pencil me-2"></i>Edit User</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <ul class="mb-0"><?php foreach ($errors as $e): ?>
                                    <li><?= e($e) ?></li><?php endforeach; ?>
                            </ul>
                        </div><?php endif; ?>

                    <form method="POST">
                        <?= csrfField() ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="name"
                                    value="<?= e(post('name', $user->name)) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" name="email"
                                    value="<?= e(post('email', $user->email)) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">New Password</label>
                                <input type="password" class="form-control" name="password" minlength="8">
                                <small class="text-muted">Leave blank to keep current password</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone</label>
                                <input type="text" class="form-control" name="phone"
                                    value="<?= e(post('phone', $user->phone)) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Role</label>
                                <select class="form-select" name="role">
                                    <?php foreach (ROLE_LABELS as $key => $info): ?>
                                        <option value="<?= $key ?>" <?= post('role', $user->role) === $key ? 'selected' : '' ?>><?= e($info['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Department</label>
                                <select class="form-select" name="department_id">
                                    <option value="">No department</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?= $dept->id ?>" <?= post('department_id', $user->department_id) == $dept->id ? 'selected' : '' ?>><?= e($dept->name) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <hr class="my-4">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save
                            Changes</button>
                        <a href="<?= APP_URL ?>/?page=users" class="btn btn-outline-secondary">Cancel</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>