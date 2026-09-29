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

// ---- Messenger connections (Plan #35) --------------------------------------
$channelFlash = null;      // [type, message]
$connectLinks = [];        // channel => ['token' => ..., 'link' => ..., 'code' => ...]

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('op', '') !== '') {
    requireCsrf();
    $op = (string) post('op', '');
    $opChannel = (string) post('channel', '');

    try {
        if (!in_array($opChannel, LOKA_CHANNELS, true)) {
            throw new InvalidArgumentException('Unknown messenger channel.');
        }
        $label = CHANNEL_DEFAULTS[$opChannel]['label'];

        if ($op === 'connect_channel') {
            if (!channelEnabled($opChannel)) {
                throw new RuntimeException(ucfirst($label) . ' notifications are not enabled yet. Ask an administrator.');
            }
            if ($opChannel === 'telegram') {
                $botUser = trim(channelConfig('telegram', 'telegram_bot_username'));
                if ($botUser === '') {
                    throw new RuntimeException('The Telegram bot is not configured yet. Ask an administrator to set the bot username.');
                }
                $token = channelMintLinkToken((int) userId(), 'telegram');
                $connectLinks['telegram'] = [
                    'token' => $token,
                    'link'  => 'https://t.me/' . $botUser . '?start=' . $token,
                    'code'  => '/start ' . $token,
                ];
                $channelFlash = ['info', 'Open the link below (or send the code to the bot) within 30 minutes to link this account.'];
            } else {
                $token = channelMintLinkToken((int) userId(), 'viber');
                $connectLinks['viber'] = [
                    'token' => $token,
                    'link'  => 'viber://forward?text=' . rawurlencode('/start ' . $token),
                    'code'  => '/start ' . $token,
                ];
                $channelFlash = ['info', 'Send the code below to the LOKA Viber bot within 30 minutes to link this account.'];
            }
            auditLog('channel_connect_started', 'user', (int) userId(), null, ['channel' => $opChannel]);
        } elseif ($op === 'disconnect_channel') {
            channelClearBinding((int) userId(), $opChannel);
            $channelFlash = ['success', ucfirst($label) . ' disconnected.'];
        }
    } catch (Throwable $e) {
        $channelFlash = ['danger', $e->getMessage()];
    }

    // refresh bindings for display
    $channelBindings = channelGetBindings((int) userId());
} else {
    $channelBindings = channelGetBindings((int) userId());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('op', '') === '') {
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

    <?php if ($channelFlash): ?>
    <div class="alert alert-<?= e($channelFlash[0]) ?> alert-dismissible fade show">
        <?= e($channelFlash[1]) ?>
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

            <!-- Messenger connections (Plan #35) -->
            <div class="card mt-4">
                <div class="card-header bg-white">
                    <h6 class="mb-0"><i class="bi bi-chat-heart me-2"></i>Messenger Alerts</h6>
                </div>
                <div class="card-body">
                    <?php foreach (LOKA_CHANNELS as $chName): ?>
                    <?php
                        $chLabel = CHANNEL_DEFAULTS[$chName]['label'];
                        $chIcon = CHANNEL_DEFAULTS[$chName]['icon'];
                        $chBinding = null;
                        foreach ($channelBindings as $cb) {
                            if ($cb->channel === $chName) {
                                $chBinding = $cb;
                                break;
                            }
                        }
                    ?>
                    <div class="d-flex align-items-center justify-content-between border rounded p-2 mb-2">
                        <div class="me-2">
                            <div class="fw-semibold"><i class="bi <?= e($chIcon) ?> me-1"></i><?= e($chLabel) ?></div>
                            <?php if ($chBinding): ?>
                            <div class="small text-muted">
                                <span class="badge bg-success">Connected</span>
                                via <?= e($chBinding->linked_via === 'admin' ? 'administrator' : 'self-link') ?>
                                · <?= e(date('M j, Y', strtotime((string) $chBinding->linked_at))) ?>
                            </div>
                            <?php else: ?>
                            <div class="small text-muted">Not connected</div>
                            <?php endif; ?>
                        </div>
                        <div class="text-nowrap">
                            <?php if ($chBinding): ?>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Disconnect <?= e($chLabel) ?> alerts?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="op" value="disconnect_channel">
                                <input type="hidden" name="channel" value="<?= e($chName) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">Disconnect</button>
                            </form>
                            <?php else: ?>
                            <form method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="op" value="connect_channel">
                                <input type="hidden" name="channel" value="<?= e($chName) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-primary">Connect</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($chBinding): ?>
                    <div class="small text-muted mb-2">Chat: <code><?= e($chBinding->chat_id) ?></code></div>
                    <?php endif; ?>
                    <?php endforeach; ?>

                    <?php foreach ($connectLinks as $chName => $cl): ?>
                    <div class="alert alert-info small mb-2">
                        <div class="fw-semibold mb-1">
                            <i class="bi <?= e(CHANNEL_DEFAULTS[$chName]['icon']) ?> me-1"></i>
                            Finish linking <?= e(CHANNEL_DEFAULTS[$chName]['label']) ?> (code expires in 30 minutes):
                        </div>
                        <div class="mb-1">1. Open this one-time link: <a href="<?= e($cl['link']) ?>" class="fw-bold" rel="noopener"><?= e($cl['link']) ?></a></div>
                        <div>2. Or send this code to the bot: <code class="user-select-all"><?= e($cl['code']) ?></code></div>
                    </div>
                    <?php endforeach; ?>

                    <p class="form-text small mb-0">
                        Linked accounts receive LOKA Fleet alerts on that messenger. Admins can also set these for you in User Management.
                    </p>
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
