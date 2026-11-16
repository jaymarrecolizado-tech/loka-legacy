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
$security = Security::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $name = postSafe('name', '', 100);
    $email = $security->sanitizeEmail(post('email'));
    $officialEmail = normalizeOptionalEmail($security->sanitizeEmail(post('official_email')));
    $password = post('password');
    $phone = postSafe('phone', '', 20);
    $role = (string) post('role');
    $departmentId = postInt('department_id') ?: null;

    if (empty($name))
        $errors[] = 'Name is required';
    if (empty($email))
        $errors[] = 'Individual email is required';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors[] = 'Invalid individual email format';
    if ($officialEmail !== '' && !filter_var($officialEmail, FILTER_VALIDATE_EMAIL))
        $errors[] = 'Invalid official email format';
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

            if ($email && userIndividualEmailTaken($email, $userId)) {
                db()->rollback();
                $errors[] = 'This individual email is already registered';
            }

            if (empty($errors)) {
                $updateData = [
                    'name' => $name,
                    'email' => $email,
                    'official_email' => $officialEmail !== '' ? $officialEmail : null,
                    'phone' => $phone,
                    'role' => $role,
                    'department_id' => $departmentId,
                    'is_ob_approver' => post('is_ob_approver') === '1' ? 1 : 0,
                    'is_budget_officer' => post('is_budget_officer') === '1' ? 1 : 0,
                    'is_oic_budget_officer' => post('is_oic_budget_officer') === '1' ? 1 : 0,
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

                // Messenger bindings set/cleared by the admin (Plan #35).
                // Unchanged chat ids keep their original linked_via/linked_at.
                foreach (LOKA_CHANNELS as $plChannel) {
                    $plChatId = trim(postSafe($plChannel . '_chat_id', '', 100));
                    $plExisting = channelGetBinding($userId, $plChannel);
                    if ($plExisting && (string) $plExisting->chat_id === $plChatId) {
                        continue;
                    }
                    channelSetAdminBinding($userId, $plChannel, $plChatId);
                }

                // Specimen e-sign — replace file wins over clear; persist after commit
                $esignError = null;
                $res = obSaveUserEsignUpload($userId, $_FILES['signature_file'] ?? []);
                if ($res['error'] !== null) {
                    $esignError = $res['error'];
                } elseif ($res['path'] === null && post('clear_signature') === '1') {
                    obClearUserEsign($userId);
                }

                $msg = 'User updated successfully.';
                if ($res['path'] !== null) {
                    $msg = 'User updated. E-sign saved.';
                } elseif ($res['error'] === null && post('clear_signature') === '1') {
                    $msg = 'User updated. E-sign removed.';
                }
                if ($esignError !== null) {
                    $msg .= ' (Specimen e-sign failed: ' . $esignError . ')';
                }
                redirectWith('/?page=users&action=edit&id=' . $userId, 'success', $msg);
            }
        } catch (Exception $e) {
            db()->rollback();
            error_log('Failed to update user #' . $userId . ': ' . $e->getMessage());
            $dup = $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062;
            $errors[] = $dup ? 'This individual email is already registered' : 'Failed to update user';
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
                                <label class="form-label">Individual Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" name="email" id="individualEmail"
                                    value="<?= e(post('email', $user->email)) ?>" required autocomplete="off">
                                <small class="text-muted">Login and notifications. Must be unique.</small>
                                <div id="individualEmailHint" class="form-text"></div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Official / Office Email</label>
                                <input type="email" class="form-control" name="official_email"
                                    value="<?= e(post('official_email', $user->official_email ?? '')) ?>">
                                <small class="text-muted">Shared unit mailbox is allowed (not unique).</small>
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
                                <label class="form-label">Specimen E-sign <span class="text-muted">(PNG/JPG ≤ 3 MB)</span></label>
                                <?php
                                $esignPath = obUserEsignPath((int) $user->id);
                                $esignStamp = ($esignPath !== null && is_file(BASE_PATH . '/' . $esignPath))
                                    ? (int) filemtime(BASE_PATH . '/' . $esignPath) : time();
                                ?>
                                <?php if ($esignPath !== null): ?>
                                <div class="border rounded bg-white p-2 mb-2 d-flex align-items-center gap-3 flex-wrap">
                                    <img src="?page=file-view&file=<?= urlencode($esignPath) ?>&t=<?= $esignStamp ?>" alt="Saved e-sign" style="max-height:80px; max-width:280px; object-fit:contain;">
                                    <div class="form-check mb-0">
                                        <input class="form-check-input" type="checkbox" name="clear_signature" value="1" id="clearSigChk">
                                        <label class="form-check-label small" for="clearSigChk">Remove saved e-sign</label>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <input type="file" class="form-control" name="signature_file" id="signatureFileInput" accept=".png,.jpg,.jpeg,image/png,image/jpeg">
                                <small class="text-muted"><?= $esignPath !== null
                                    ? 'Choose a new PNG/JPG and click Save Changes to replace this e-sign.'
                                    : 'Upload a PNG/JPG, then Save Changes. Stamped on OB Pass Slips and approved gas vouchers.' ?></small>
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
                            <div class="col-12">
                                <label class="form-label d-block">Budget Officer</label>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="is_budget_officer" value="1"
                                        role="switch" id="budgetOfficerChk" <?= post('is_budget_officer', $user->is_budget_officer ? '1' : '0') === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="budgetOfficerChk">
                                        Can act as <strong>Budget Officer</strong> on gas vouchers (pending budget step)
                                    </label>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="is_oic_budget_officer" value="1"
                                        role="switch" id="oicBudgetOfficerChk" <?= post('is_oic_budget_officer', $user->is_oic_budget_officer ? '1' : '0') === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="oicBudgetOfficerChk">
                                        Can act as <strong>OIC Budget Officer</strong> on gas vouchers
                                    </label>
                                </div>
                            </div>
                            <?php $editChannelBindings = [];
                            foreach (channelGetBindings($userId) as $ecb) {
                                $editChannelBindings[$ecb->channel] = $ecb;
                            } ?>
                            <div class="col-12">
                                <label class="form-label d-block">Messenger chat IDs <span class="text-muted fw-normal">(Plan #35 — optional)</span></label>
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label small mb-1">Telegram chat ID</label>
                                        <input type="text" class="form-control form-control-sm" name="telegram_chat_id"
                                               value="<?= e(postSafe('telegram_chat_id', $editChannelBindings['telegram']->chat_id ?? '', 100)) ?>"
                                               placeholder="e.g. 123456789">
                                    </div>
                                </div>
                                <small class="text-muted">Clear a field to unlink that messenger. Unchanged values keep the original link source (self / administrator).</small>
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

<?php
$emailCheckExcludeId = (int) $userId;
require __DIR__ . '/partials/email_unique_check.php';
?>
<script>
(function () {
    var file = document.getElementById('signatureFileInput');
    var clear = document.getElementById('clearSigChk');
    if (!file || !clear) return;
    file.addEventListener('change', function () {
        if (file.files && file.files.length) {
            clear.checked = false;
        }
    });
})();
</script>
<?php
require_once INCLUDES_PATH . '/footer.php';
?>
?>