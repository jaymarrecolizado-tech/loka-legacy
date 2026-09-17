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
$validRoles = array_keys(ROLE_LABELS);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $name = postSafe('name', '', 100);
    $email = post('email');
    $password = post('password');
    $phone = postSafe('phone', '', 20);
    $role = (string) post('role');
    $departmentId = postInt('department_id') ?: null;

    if (empty($name))
        $errors[] = 'Name is required';
    if (empty($email))
        $errors[] = 'Email is required';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors[] = 'Invalid email format';
    if ($password && strlen($password) < 8)
        $errors[] = 'Password must be at least 8 characters';
    if ($role === '')
        $errors[] = 'Role is required';
    elseif (!in_array($role, $validRoles, true))
        $errors[] = 'Invalid role selected';

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
                    'is_ob_approver' => post('is_ob_approver') === '1' ? 1 : 0,
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

                // Specimen e-sign (Plan #23) — file writes after commit
                $esignError = null;
                if (post('clear_signature') === '1') {
                    obClearUserEsign($userId);
                }
                $res = obSaveUserEsignUpload($userId, $_FILES['signature_file'] ?? []);
                if ($res['error'] !== null) {
                    $esignError = $res['error'];
                }

                redirectWith('/?page=users', 'success', 'User updated successfully.'
                    . ($esignError !== null ? ' (Specimen e-sign failed: ' . $esignError . ')' : ''));
            }
        } catch (Exception $e) {
            db()->rollback();
            error_log('Failed to update user #' . $userId . ': ' . $e->getMessage());
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

                    <form method="POST" enctype="multipart/form-data">
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
                            <div class="col-12">
                                <label class="form-label">Specimen E-sign <span class="text-muted">(PNG/JPG ≤ 1 MB)</span></label>
                                <?php $esignPath = obUserEsignPath((int) $user->id); ?>
                                <?php if ($esignPath !== null): ?>
                                <div class="border rounded bg-white p-2 mb-2 d-flex align-items-center gap-3">
                                    <img src="?page=file-view&file=<?= urlencode($esignPath) ?>" alt="Specimen e-sign" style="max-height:64px; max-width:200px; object-fit:contain;">
                                    <div class="form-check mb-0">
                                        <input class="form-check-input" type="checkbox" name="clear_signature" value="1" id="clearSigChk">
                                        <label class="form-check-label small" for="clearSigChk">Remove saved e-sign</label>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <input type="file" class="form-control" name="signature_file" accept=".png,.jpg,.jpeg,image/png,image/jpeg">
                                <small class="text-muted"><?= $esignPath !== null ? 'Upload a new file to replace the specimen.' : 'Used to stamp OB Pass Slips automatically when this user approves or a guard stamps departure.' ?></small>
                            </div>
                            <div class="col-12">
                                <label class="form-label d-block">OB Approver</label>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="is_ob_approver" value="1"
                                        role="switch" id="obApproverChk" <?= post('is_ob_approver', $user->is_ob_approver ? '1' : '0') === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="obApproverChk">
                                        Can act as <strong>Immediate Supervisor</strong> for OB Pass Slips
                                    </label>
                                </div>
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