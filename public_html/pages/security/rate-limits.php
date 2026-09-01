<?php
/**
 * All Father — clear rate limits & unlock accounts
 */

requireSystemControl();

$pageTitle = 'Rate Limits & Lockouts';
$security = Security::getInstance();
$auth = new Auth();
$flash = null;

/**
 * TARGET's Auth class has no unlockAccount(); replicate SOURCE behavior:
 * clear failed attempts + locked_until, then clear login rate limits.
 */
if (!function_exists('securityPortUnlockAccount')) {
    function securityPortUnlockAccount(Security $security, int $userId, ?string $email = null): void
    {
        db()->update(
            'users',
            [
                'failed_login_attempts' => 0,
                'locked_until' => null,
                'updated_at' => date(DATETIME_FORMAT),
            ],
            'id = ?',
            [$userId]
        );
        if ($email) {
            $security->clearRateLimits('login', $email);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $op = post('op', '');

    if ($op === 'unlock_user') {
        $userId = postInt('user_id');
        $user = db()->fetch(
            "SELECT id, email, name FROM users WHERE id = ? AND deleted_at IS NULL",
            [$userId]
        );
        if ($user) {
            securityPortUnlockAccount($security, (int) $user->id, $user->email);
            $security->logSecurityEvent(
                'account_unlocked',
                "All Father unlocked user #{$user->id} ({$user->email})",
                userId()
            );
            $flash = ['success', "Unlocked {$user->email}."];
        } else {
            $flash = ['danger', 'User not found.'];
        }
    } elseif ($op === 'clear_identifier') {
        $action = postSafe('rl_action', '', 50);
        $identifier = postSafe('identifier', '', 255);
        $allowed = ['login', 'login_ip', 'password_reset', 'password_change'];
        if ($action && $identifier && in_array($action, $allowed, true)) {
            $security->clearRateLimits($action, $identifier);
            if ($action === 'login' && filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
                $u = db()->fetch(
                    "SELECT id FROM users WHERE email = ? AND deleted_at IS NULL",
                    [$identifier]
                );
                if ($u) {
                    securityPortUnlockAccount($security, (int) $u->id, $identifier);
                }
            }
            $security->logSecurityEvent(
                'rate_limit_cleared',
                "Cleared {$action} for {$identifier}",
                userId()
            );
            $flash = ['success', "Cleared {$action} for {$identifier}."];
        } else {
            $flash = ['danger', 'Invalid action or identifier.'];
        }
    } elseif ($op === 'clear_by_form') {
        $email = $security->sanitizeEmail(post('email', ''));
        $ip = postSafe('ip_address', '', 45);
        $cleared = [];
        if ($email) {
            $security->clearRateLimits('login', $email);
            $security->clearRateLimits('password_reset', $email);
            $u = db()->fetch(
                "SELECT id FROM users WHERE email = ? AND deleted_at IS NULL",
                [$email]
            );
            if ($u) {
                securityPortUnlockAccount($security, (int) $u->id, $email);
                $security->clearRateLimits('password_change', (string) $u->id);
            }
            $cleared[] = "email {$email}";
        }
        if ($ip !== '') {
            $security->clearRateLimits('login_ip', $ip);
            $cleared[] = "IP {$ip}";
        }
        if ($cleared) {
            $security->logSecurityEvent(
                'rate_limit_cleared',
                'Cleared: ' . implode(', ', $cleared),
                userId()
            );
            $flash = ['success', 'Cleared: ' . implode(', ', $cleared) . '.'];
        } else {
            $flash = ['warning', 'Enter an email and/or IP address.'];
        }
    }
}

$lockedUsers = db()->fetchAll(
    "SELECT id, email, name, role, failed_login_attempts, locked_until, last_failed_login
     FROM users
     WHERE deleted_at IS NULL
       AND (
         (locked_until IS NOT NULL AND locked_until > NOW())
         OR failed_login_attempts >= ?
       )
     ORDER BY locked_until DESC, failed_login_attempts DESC
     LIMIT 100",
    [RATE_LIMIT_LOGIN_ATTEMPTS]
);

$activeLimits = db()->fetchAll(
    "SELECT action, identifier, COUNT(*) AS attempts, MAX(created_at) AS last_hit, MAX(ip_address) AS ip_address
     FROM rate_limits
     WHERE action IN ('login', 'login_ip', 'password_reset', 'password_change')
       AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
     GROUP BY action, identifier
     ORDER BY attempts DESC, last_hit DESC
     LIMIT 100",
    [RATE_LIMIT_LOGIN_WINDOW]
);

$recentLogs = db()->fetchAll(
    "SELECT event, details, ip_address, created_at, user_id
     FROM security_logs
     WHERE event IN ('rate_limit_cleared', 'account_unlocked', 'account_locked', 'login_rate_limited')
     ORDER BY created_at DESC
     LIMIT 30"
);

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="mb-2">
        <h4 class="mb-1"><i class="bi bi-shield-lock me-2"></i>Rate Limits &amp; Lockouts</h4>
        <p class="text-muted mb-0">All Father only — unlock accounts and clear login throttles</p>
    </div>

    <?php require __DIR__ . '/partials/subnav.php'; ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash[0]) ?> mb-4"><?= e($flash[1]) ?></div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-body">
            <h5 class="mb-3">Clear by email / IP</h5>
            <form method="POST" class="row g-3 align-items-end">
                <?= csrfField() ?>
                <input type="hidden" name="op" value="clear_by_form">
                <div class="col-md-4">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" placeholder="user@example.com">
                </div>
                <div class="col-md-4">
                    <label class="form-label">IP address</label>
                    <input type="text" name="ip_address" class="form-control" placeholder="192.168.x.x">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary">Clear</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header fw-semibold">Locked / throttled accounts</div>
                <div class="table-responsive">
                    <table class="table table-striped mb-0 no-datatable">
                        <thead><tr><th>User</th><th>Attempts</th><th>Locked until</th><th></th></tr></thead>
                        <tbody>
                        <?php if (empty($lockedUsers)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">None</td></tr>
                        <?php else: foreach ($lockedUsers as $u): ?>
                            <tr>
                                <td>
                                    <div class="fw-medium"><?= e($u->name) ?></div>
                                    <small class="text-muted"><?= e($u->email) ?></small>
                                </td>
                                <td><?= (int) $u->failed_login_attempts ?></td>
                                <td><?= $u->locked_until ? e($u->locked_until) : '—' ?></td>
                                <td>
                                    <form method="POST">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="op" value="unlock_user">
                                        <input type="hidden" name="user_id" value="<?= (int) $u->id ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm">Unlock</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card">
                <div class="card-header fw-semibold">Active rate limits (window)</div>
                <div class="table-responsive">
                    <table class="table table-striped mb-0 no-datatable">
                        <thead><tr><th>Action</th><th>Identifier</th><th>#</th><th></th></tr></thead>
                        <tbody>
                        <?php if (empty($activeLimits)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">None</td></tr>
                        <?php else: foreach ($activeLimits as $row): ?>
                            <tr>
                                <td><code><?= e($row->action) ?></code></td>
                                <td class="small text-break"><?= e($row->identifier) ?></td>
                                <td><?= (int) $row->attempts ?></td>
                                <td>
                                    <form method="POST">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="op" value="clear_identifier">
                                        <input type="hidden" name="rl_action" value="<?= e($row->action) ?>">
                                        <input type="hidden" name="identifier" value="<?= e($row->identifier) ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm">Clear</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header fw-semibold">Recent security events</div>
        <div class="table-responsive">
            <table class="table table-striped mb-0 no-datatable">
                <thead><tr><th>When</th><th>Event</th><th>Details</th></tr></thead>
                <tbody>
                <?php if (empty($recentLogs)): ?>
                    <tr><td colspan="3" class="text-center text-muted py-4">No recent events</td></tr>
                <?php else: foreach ($recentLogs as $log): ?>
                    <tr>
                        <td class="small text-nowrap"><?= e($log->created_at) ?></td>
                        <td><code><?= e($log->event) ?></code></td>
                        <td class="small"><?= e($log->details) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
