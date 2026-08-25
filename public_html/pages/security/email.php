<?php
/**
 * All Father — Email delivery mode & queue tools
 */

requireSystemControl();

require_once INCLUDES_PATH . '/mail_delivery.php';

$pageTitle = 'Email Delivery';
$flash = null;
$queue = new EmailQueue();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $op = post('op', '');

    try {
        if ($op === 'save_mode') {
            $mode = postSafe('email_delivery_mode', EMAIL_MODE_IMMEDIATE, 20);
            if (!in_array($mode, [EMAIL_MODE_IMMEDIATE, EMAIL_MODE_QUEUED, EMAIL_MODE_HYBRID], true)) {
                throw new InvalidArgumentException('Invalid delivery mode.');
            }
            emailSaveSetting('email_delivery_mode', $mode);
            auditLog('email_delivery_mode_updated', 'settings', null, null, ['mode' => $mode]);
            $flash = ['success', 'Email delivery mode saved: ' . $mode . '.'];
        } elseif ($op === 'rotate_cron_secret') {
            $secret = bin2hex(random_bytes(16));
            emailSaveSetting('cron_secret', $secret);
            auditLog('cron_secret_rotated', 'settings', null, null, ['rotated' => true]);
            $flash = ['success', 'Cron secret rotated. Update your cron URL.'];
        } elseif ($op === 'process_queue') {
            $r = $queue->process(50);
            $flash = ['success', "Processed queue: sent {$r['sent']}, failed {$r['failed']}, skipped {$r['skipped']}."];
        }
    } catch (Throwable $e) {
        $flash = ['danger', $e->getMessage()];
    }
}

$mode = emailDeliveryMode();
$cronSecret = emailCronSecret();
$stats = $queue->getStats();
$cronEmailUrl = APP_URL . '/?page=cron&action=email&key=' . rawurlencode($cronSecret);
$cronSmsUrl = APP_URL . '/?page=cron&action=sms&key=' . rawurlencode($cronSecret);
$mailEnabled = defined('MAIL_ENABLED') && MAIL_ENABLED;

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid px-4 py-4">
    <div class="mb-2">
        <h4 class="mb-1">Email Delivery</h4>
        <p class="text-muted small mb-0">
            Choose how LOKA sends email. Immediate needs no cron (pages may wait on SMTP).
        </p>
    </div>

    <?php require __DIR__ . '/partials/subnav.php'; ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash[0]) ?> mb-4"><?= e($flash[1]) ?></div>
    <?php endif; ?>

    <?php if (!$mailEnabled): ?>
        <div class="alert alert-warning mb-4">
            <code>MAIL_ENABLED</code> is false in <code>.env</code>. Enable it and configure SMTP before testing real delivery.
        </div>
    <?php endif; ?>

    <div class="row g-4 mb-4">
        <div class="col-6 col-md-3">
            <div class="card"><div class="card-body py-3 text-center">
                <div class="fs-5 fw-semibold text-uppercase"><?= e($mode) ?></div>
                <div class="small text-muted">Delivery mode</div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card"><div class="card-body py-3 text-center">
                <div class="fs-4 fw-semibold"><?= (int) ($stats['pending'] ?? 0) ?></div>
                <div class="small text-muted">Pending</div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card"><div class="card-body py-3 text-center">
                <div class="fs-4 fw-semibold"><?= (int) ($stats['sent'] ?? 0) ?></div>
                <div class="small text-muted">Sent</div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card"><div class="card-body py-3 text-center">
                <div class="fs-4 fw-semibold"><?= (int) ($stats['failed'] ?? 0) ?></div>
                <div class="small text-muted">Failed</div>
            </div></div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="mb-3">Delivery mode</h5>
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="op" value="save_mode">

                        <label class="d-flex align-items-start gap-2 border rounded p-3 mb-2" style="cursor:pointer;">
                            <input type="radio" name="email_delivery_mode" value="immediate" class="form-check-input mt-1"
                                <?= $mode === EMAIL_MODE_IMMEDIATE ? 'checked' : '' ?>>
                            <span>
                                <strong>Immediate</strong>
                                <span class="d-block small text-muted">Send in the same request (no cron). Best for VPS testing; UI may feel slower.</span>
                            </span>
                        </label>

                        <label class="d-flex align-items-start gap-2 border rounded p-3 mb-2" style="cursor:pointer;">
                            <input type="radio" name="email_delivery_mode" value="queued" class="form-check-input mt-1"
                                <?= $mode === EMAIL_MODE_QUEUED ? 'checked' : '' ?>>
                            <span>
                                <strong>Queued</strong>
                                <span class="d-block small text-muted">Fast pages; drain with Process now, CLI cron, or HTTP cron URL below.</span>
                            </span>
                        </label>

                        <label class="d-flex align-items-start gap-2 border rounded p-3 mb-3" style="cursor:pointer;">
                            <input type="radio" name="email_delivery_mode" value="hybrid" class="form-check-input mt-1"
                                <?= $mode === EMAIL_MODE_HYBRID ? 'checked' : '' ?>>
                            <span>
                                <strong>Hybrid</strong>
                                <span class="d-block small text-muted">Critical templates sync now; others wait in the queue.</span>
                            </span>
                        </label>

                        <button type="submit" class="btn btn-primary">Save mode</button>
                    </form>

                    <form method="POST" class="mt-4">
                        <?= csrfField() ?>
                        <input type="hidden" name="op" value="process_queue">
                        <button type="submit" class="btn btn-secondary">Process email queue now</button>
                    </form>
                    <p class="form-text small mt-2 mb-0">
                        Admins can also use <a href="<?= APP_URL ?>/?page=settings&action=email-queue">Settings → Email Queue</a>.
                    </p>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="mb-3">HTTP cron (hosting-friendly)</h5>
                    <p class="text-muted small mb-3">
                        Instead of CLI PHP, schedule a URL hit every 1–2 minutes when using Queued or Hybrid.
                    </p>
                    <label class="form-label">Email queue URL</label>
                    <input type="text" class="form-control font-monospace small mb-3" readonly value="<?= e($cronEmailUrl) ?>"
                           onclick="this.select()">
                    <label class="form-label">SMS queue URL</label>
                    <input type="text" class="form-control font-monospace small mb-3" readonly value="<?= e($cronSmsUrl) ?>"
                           onclick="this.select()">
                    <pre class="bg-light border rounded p-2 small overflow-auto mb-3">curl -s "<?= e($cronEmailUrl) ?>"</pre>
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="op" value="rotate_cron_secret">
                        <button type="submit" class="btn btn-outline-danger"
                                onclick="return confirm('Rotate cron secret? Update your scheduled cron afterward.');">
                            Rotate cron secret
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
