<?php
/**
 * LOKA - User Profile Page
 */

$pageTitle = 'My Profile';
$errors = [];
$success = false;
$security = Security::getInstance();

$user = db()->fetch(
    "SELECT u.*, d.name as department_name FROM users u 
     LEFT JOIN departments d ON u.department_id = d.id 
     WHERE u.id = ?",
    [userId()]
);

// Get all departments for dropdown
$departments = db()->fetchAll(
    "SELECT id, name FROM departments WHERE deleted_at IS NULL ORDER BY name"
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    
    $name = postSafe('name', '', 100);
    $phone = postSafe('phone', '', 20);
    $departmentId = postInt('department_id') ?: null;
    $currentPassword = post('current_password');
    $newPassword = post('new_password');
    $confirmPassword = post('confirm_password');
    
    if (empty($name)) $errors[] = 'Name is required';
    
    // Password change validation
    if ($newPassword) {
        // Rate limit password changes
        if ($security->isRateLimited('password_change', (string)userId(), RATE_LIMIT_PASSWORD_ATTEMPTS, RATE_LIMIT_PASSWORD_WINDOW)) {
            $errors[] = 'Too many password change attempts. Please try again later.';
        } else {
            if (empty($currentPassword)) {
                $errors[] = 'Current password is required to change password';
            } elseif (!password_verify($currentPassword, $user->password)) {
                $errors[] = 'Current password is incorrect';
                $security->recordAttempt('password_change', (string)userId());
                $security->logSecurityEvent('password_change_failed', 'Invalid current password', userId());
            }
            
            // Validate new password against policy
            $passwordErrors = $security->validatePassword($newPassword);
            $errors = array_merge($errors, $passwordErrors);
            
            if ($newPassword !== $confirmPassword) {
                $errors[] = 'New passwords do not match';
            }
            
            // Prevent reusing old password
            if ($currentPassword && password_verify($newPassword, $user->password)) {
                $errors[] = 'New password cannot be the same as current password';
            }
        }
    }
    
        if (empty($errors)) {
        $updateData = [
            'name' => $name,
            'phone' => $phone,
            'department_id' => $departmentId,
            'updated_at' => date(DATETIME_FORMAT)
        ];
        
        if ($newPassword) {
            $auth = new Auth();
            $updateData['password'] = $auth->hashPassword($newPassword);
            $security->clearRateLimits('password_change', (string)userId());
            $security->logSecurityEvent('password_changed', 'Password successfully changed', userId());
        }
        
        db()->update('users', $updateData, 'id = ?', [userId()]);
        
        // Refresh user data and rebuild session properly
        $updatedUser = db()->fetch(
            "SELECT u.*, d.name as department_name FROM users u 
             LEFT JOIN departments d ON u.department_id = d.id 
             WHERE u.id = ?",
            [userId()]
        );
        
        if ($updatedUser) {
            $_SESSION['user_name'] = $updatedUser->name;
            $_SESSION['user'] = $updatedUser;
        }
        
        auditLog('profile_updated', 'user', userId());
        $success = true;
        
        // Update local $user variable for display
        $user = $updatedUser;
    }
}

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="mb-4">
        <h4 class="mb-1">My Profile</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
                <li class="breadcrumb-item active">Profile</li>
            </ol>
        </nav>
    </div>
    
    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        Profile updated successfully.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <div class="row g-4">
        <div class="col-lg-4">
            <!-- Profile Card -->
            <div class="card">
                <div class="card-body text-center">
                    <div class="avatar-circle mx-auto mb-3" style="width:80px;height:80px;font-size:2rem;background:#0d6efd;color:#fff;">
                        <?= strtoupper(substr($user->name, 0, 1)) ?>
                    </div>
                    <h5 class="mb-1"><?= e($user->name) ?></h5>
                    <p class="text-muted mb-2"><?= e($user->email) ?></p>
                    <?= roleBadge($user->role) ?>
                    <hr>
                    <div class="text-start">
                        <p class="mb-1"><strong>Department:</strong> <?= e($user->department_name ?: 'None') ?></p>
                        <p class="mb-1"><strong>Phone:</strong> <?= e($user->phone ?: '-') ?></p>
                        <p class="mb-0"><strong>Member since:</strong> <?= formatDate($user->created_at) ?></p>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-8">
            <!-- Edit Profile Form -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-pencil me-2"></i>Edit Profile</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
                    </div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <?= csrfField() ?>
                        
                        <h6 class="text-muted mb-3">Basic Information</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="name" value="<?= e(post('name', $user->name)) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" value="<?= e($user->email) ?>" disabled>
                                <small class="text-muted">Contact admin to change email</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone</label>
                                <input type="tel" class="form-control" name="phone" value="<?= e(post('phone', $user->phone)) ?>" placeholder="e.g., 09171234567">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Department</label>
                                <select class="form-select" name="department_id">
                                    <option value="">Select department...</option>
                                    <?php foreach ($departments as $dept): ?>
                                    <option value="<?= $dept->id ?>" <?= (post('department_id', $user->department_id) == $dept->id) ? 'selected' : '' ?>>
                                        <?= e($dept->name) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <h6 class="text-muted mb-3">Change Password</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Current Password</label>
                                <input type="password" class="form-control" name="current_password">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">New Password</label>
                                <input type="password" class="form-control" name="new_password" minlength="<?= PASSWORD_MIN_LENGTH ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Confirm New Password</label>
                                <input type="password" class="form-control" name="confirm_password">
                            </div>
                        </div>
                        <small class="text-muted">
                            Leave blank to keep current password. Requirements: <?= e($security->getPasswordRequirements()) ?>
                        </small>
                        
                        <hr class="my-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Save Changes
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
