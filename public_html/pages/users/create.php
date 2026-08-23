<?php
/**
 * LOKA - Create User Page
 */

requireRole(ROLE_ADMIN);

$pageTitle = 'Add User';
$errors = [];
$security = Security::getInstance();

$departments = db()->fetchAll("SELECT * FROM departments WHERE deleted_at IS NULL AND status = 'active' ORDER BY name");

// Valid roles whitelist
$validRoles = array_keys(ROLE_LABELS);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $name = postSafe('name', '', 100);
    $email = $security->sanitizeEmail(post('email'));
    $password = post('password'); // Don't sanitize password
    $phone = postSafe('phone', '', 20);
    $role = post('role');
    $departmentId = postInt('department_id') ?: null;

    // Validation
    if (empty($name))
        $errors[] = 'Name is required';
    if (empty($email))
        $errors[] = 'Email is required';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors[] = 'Invalid email format';

    if (empty($password)) {
        $errors[] = 'Password is required';
    } else {
        // Validate password against policy
        $passwordErrors = $security->validatePassword($password);
        $errors = array_merge($errors, $passwordErrors);
    }

    if (empty($role)) {
        $errors[] = 'Role is required';
    } elseif (!in_array($role, $validRoles)) {
        $errors[] = 'Invalid role selected';
    }

    if ($email) {
        $existing = db()->fetch("SELECT id FROM users WHERE email = ? AND deleted_at IS NULL", [$email]);
        if ($existing)
            $errors[] = 'Email already exists';
    }

    if (empty($errors)) {
        db()->beginTransaction();

        try {
            $auth = new Auth();
            $userId = db()->insert('users', [
                'name' => $name,
                'email' => $email,
                'password' => $auth->hashPassword($password),
                'phone' => $phone,
                'role' => $role,
                'department_id' => $departmentId,
                'status' => USER_ACTIVE,
                'failed_login_attempts' => 0,
                'created_at' => date(DATETIME_FORMAT),
                'updated_at' => date(DATETIME_FORMAT)
            ]);

            auditLog('user_created', 'user', $userId);
            $security->logSecurityEvent('user_created', "New user: $email ($role)", userId());

            db()->commit();
            clearUserCache(); // Clear user cache after creating user
            redirectWith('/?page=users', 'success', 'User created successfully.');
        } catch (Exception $e) {
            db()->rollback();
            $errors[] = 'Failed to create user.';
        }
    }
}

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="mb-4">
        <h4 class="mb-1">Add User</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>/?page=users">Users</a></li>
                <li class="breadcrumb-item active">Add</li>
            </ol>
        </nav>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-person-plus me-2"></i>User Details</h5>
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
                                <input type="text" class="form-control" name="name" value="<?= e(post('name', '')) ?>"
                                    required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" name="email"
                                    value="<?= e(post('email', '')) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" name="password" required
                                    minlength="<?= PASSWORD_MIN_LENGTH ?>">
                                <small class="text-muted"><?= e($security->getPasswordRequirements()) ?></small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone</label>
                                <input type="text" class="form-control" name="phone"
                                    value="<?= e(post('phone', '')) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Role <span class="text-danger">*</span></label>
                                <select class="form-select" name="role" required>
                                    <option value="">Select role...</option>
                                    <?php foreach (ROLE_LABELS as $key => $info): ?>
                                        <option value="<?= $key ?>" <?= post('role') === $key ? 'selected' : '' ?>>
                                            <?= e($info['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Department</label>
                                <select class="form-select" name="department_id">
                                    <option value="">No department</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?= $dept->id ?>" <?= post('department_id') == $dept->id ? 'selected' : '' ?>><?= e($dept->name) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <hr class="my-4">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Create
                            User</button>
                        <a href="<?= APP_URL ?>/?page=users" class="btn btn-outline-secondary">Cancel</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>