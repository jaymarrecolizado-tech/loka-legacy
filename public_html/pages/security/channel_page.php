<?php
/**
 * All Father — messenger channel settings & logs (Plan #35).
 * Shared implementation for security/telegram.php + security/viber.php,
 * parameterized by $channel ('telegram' | 'viber').
 */

if (!isset($channel) || !in_array($channel, LOKA_CHANNELS, true)) {
    redirectWith('/?page=dashboard', 'danger', 'Unknown channel.');
}

requireSystemControl();

$pageTitle = ucfirst($channel) === 'Telegram' ? 'Telegram Notifications' : 'Viber Notifications';
$flash = null;
$queue = new ChannelQueue();
$label = CHANNEL_DEFAULTS[$channel]['label'];
$icon = CHANNEL_DEFAULTS[$channel]['icon'];

$selectableEvents = channelSelectableEvents();
$allowlistDefault = '*';
$secAction = $channel; // security subnav key

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $op = post('op', '');

    try {
        if ($op === 'save_settings') {
            $enabled = post($channel . '_enabled', '0') === '1' ? '1' : '0';
            $tokenKey = $channel === 'telegram' ? 'telegram_bot_token' : 'viber_auth_token';
            $token = trim(postSafe($tokenKey, '', 255));
            $secret = trim(postSafe($channel . '_webhook_secret', '', 120));
            $timeout = max(5, min(60, (int) post($channel . '_timeout', 15)));
            $maxLen = max(80, min(4000, (int) post($channel . '_max_length', (string) CHANNEL_DEFAULTS[$channel]['max_length'])));

            $mirrorEmail = post('mirror_email', '0') === '1';
            if ($mirrorEmail) {
                $allowlist = '*';
            } else {
                $selected = post('events', []);
                if (!is_array($selected)) {
                    $selected = [];
                }
                $selected = array_values(array_intersect($selected, $selectableEvents));
                $allowlist = !empty($selected) ? implode(',', $selected) : $allowlistDefault;
            }

            channelSaveSetting($channel, $channel . '_enabled', $enabled, 'boolean');
            if ($token !== '') {
                channelSaveSetting($channel, $tokenKey, $token);
            }
            channelSaveSetting($channel, $channel . '_webhook_secret', $secret);
            channelSaveSetting($channel, $channel . '_timeout', (string) $timeout, 'integer');
            channelSaveSetting($channel, $channel . '_max_length', (string) $maxLen, 'integer');
            channelSaveSetting($channel, $channel . '_event_allowlist', $allowlist);
            if ($channel === 'telegram') {
                channelSaveSetting($channel, 'telegram_bot_username', trim(postSafe('telegram_bot_username', '', 64)));
            } else {
                channelSaveSetting($channel, 'viber_sender_name', trim(postSafe('viber_sender_name', '', 60)) ?: 'LOKA Fleet');
            }
            channelConfigClearCache($channel);

            auditLog('channel_settings_updated', 'settings', null, null, [
                'channel' => $channel,
                'enabled' => $enabled,
            ]);
            $flash = ['success', $label . ' settings saved.'];
        } elseif ($op === 'test_send') {
            if (!channelEnabled($channel)) {
                throw new RuntimeException('Enable ' . $label . ' notifications before sending a test.');
            }
            $chatId = trim(postSafe('test_chat_id', '', 100));
            if ($chatId === '') {
                throw new InvalidArgumentException('Chat ID is required.');
            }
            // Telegram bots cannot message themselves — catch the common "@BotUsername" mistake early.
            if ($channel === 'telegram') {
                $botUser = ltrim((string) channelConfig('telegram', 'telegram_bot_username', ''), '@');
                $targetUser = ltrim($chatId, '@');
                if ($botUser !== '' && strcasecmp($botUser, $targetUser) === 0) {
                    throw new InvalidArgumentException(
                        'Use your personal Telegram chat ID (numeric), not the bot username @' . $botUser . '. '
                        . 'Connect via Profile → Messenger Alerts, then Poll link requests, or look up your chat id with @userinfobot.'
                    );
                }
            }
            // Telegram Bot API needs a numeric chat ID (or @channel where the bot is admin).
            // A phone number is NOT a chat_id — catch that mistake early.
            if ($channel === 'telegram') {
                if (preg_match('/^\+?[0-9][0-9\s\-]{5,}$/', $chatId) && str_starts_with(ltrim($chatId, '+'), '0')) {
                    throw new InvalidArgumentException(
                        'That looks like a phone number — Telegram bots cannot message phone numbers. '
                        . 'Use your numeric chat ID instead: message @userinfobot on Telegram to get it, '
                        . 'or use Profile → Messenger Alerts → Connect.'
                    );
                }
            }
            $msg = trim(postSafe('test_message', 'LOKA ' . $label . ' test — ' . date('Y-m-d H:i'), 4000));
            $id = $queue->queueTest($channel, $chatId, $msg, userId());
            // Send THIS test row immediately — process($channel, 1) would grab the
            // oldest pending row instead (ORDER BY id ASC) and leave the test unsent.
            $sent = $id ? $queue->processOne($id) : false;
            $row = $id ? db()->fetch("SELECT status, error_message FROM channel_logs WHERE id = ?", [$id]) : null;
            if ($sent && $row && $row->status === 'sent') {
                $flash = ['success', 'Test ' . $label . ' message sent.'];
            } elseif ($row && $row->status === 'pending') {
                $flash = ['warning', 'Test queued (pending). Run Process queue or wait for cron.'];
            } else {
                $err = $row->error_message ?? 'Unknown error';
                $flash = ['danger', 'Test failed: ' . $err];
            }
        } elseif ($op === 'process_queue') {
            $r = $queue->process($channel, 30);
            $flash = ['success', "Processed queue: sent {$r['sent']}, failed {$r['failed']}, skipped {$r['skipped']}."];
        } elseif ($op === 'register_webhook' && $channel === 'viber') {
            // Viber has no getUpdates polling — callbacks only arrive after set_webhook.
            $gw = ViberGateway::fromConfig();
            if (!$gw) {
                throw new RuntimeException('Viber is disabled or the auth token is not configured.');
            }
            $url = rtrim((string) SITE_URL, '/') . VIBER_WEBHOOK_PATH;
            $secretNow = trim((string) channelConfig('viber', 'viber_webhook_secret', ''));
            if ($secretNow !== '') {
                $url .= '?key=' . $secretNow;
            }
            $reg = $gw->setWebhook($url);
            if (!$reg['ok']) {
                throw new RuntimeException('set_webhook failed: ' . ($reg['error'] ?: 'unknown'));
            }
            auditLog('channel_webhook_registered', 'settings', null, null, ['channel' => 'viber', 'url' => $url]);
            $flash = ['success', 'Viber webhook registered: ' . $url];
        } elseif ($op === 'health_check') {
            $gw = $channel === 'telegram' ? TelegramGateway::fromConfig() : ViberGateway::fromConfig();
            if (!$gw) {
                $flash = ['danger', $label . ' is disabled or the bot token is not configured.'];
            } else {
                $h = $gw->health();
                $who = !empty($h['username']) ? ' (+' . $h['username'] . ')' : '';
                $flash = $h['ok']
                    ? ['success', $label . ' health OK' . $who . '.']
                    : ['danger', $label . ' health failed: ' . ($h['error'] ?: 'unknown')];
            }
        } elseif ($op === 'poll_updates' && $channel === 'telegram') {
            // Redeem pending /start {token} connect codes without a public webhook.
            $gw = TelegramGateway::fromConfig();
            if (!$gw) {
                throw new RuntimeException('Telegram is disabled or the bot token is not configured.');
            }
            $r = $gw->getUpdates((int) post('offset', 0));
            if (!$r['ok']) {
                throw new RuntimeException('getUpdates failed: ' . ($r['error'] ?: 'unknown'));
            }
            $n = channelRedeemTelegramUpdates($r['updates']);
            $maxId = 0;
            foreach ($r['updates'] as $u) {
                $maxId = max($maxId, (int) ($u->update_id ?? 0));
            }
            if ($maxId > 0) {
                channelSaveSetting('telegram', 'telegram_last_update_id', (string) ($maxId + 1), 'integer');
                channelConfigClearCache('telegram');
            }
            $flash = ['success', 'Polled ' . count($r['updates']) . ' update(s); processed ' . $n . ' link command(s).'];
        } elseif ($op === 'delete_log') {
            $logId = postInt('log_id');
            $row = $logId
                ? db()->fetch("SELECT id, chat_id, event_type, status FROM channel_logs WHERE id = ? AND channel = ?", [$logId, $channel])
                : null;
            if (!$row) {
                throw new InvalidArgumentException('Log not found.');
            }
            db()->delete('channel_logs', 'id = ?', [$logId]);
            auditLog('channel_log_deleted', 'channel_log', $logId, (array) $row, null);
            $qs = http_build_query(array_filter([
                'page' => 'security',
                'action' => $channel,
                'status' => postSafe('ret_status', '', 20),
                'q' => postSafe('ret_q', '', 100),
                'date_from' => postSafe('ret_date_from', '', 20),
                'date_to' => postSafe('ret_date_to', '', 20),
                'per_page' => postSafe('ret_per_page', '', 10),
                'p' => postSafe('ret_p', '', 10),
            ], static fn($v) => $v !== null && $v !== ''));
            redirectWith('/?' . $qs, 'success', ucfirst($label) . " log #{$logId} deleted.");
        } elseif ($op === 'unlink_user') {
            $targetUserId = postInt('target_user_id');
            $binding = $targetUserId
                ? db()->fetch("SELECT * FROM user_channel_bindings WHERE user_id = ? AND channel = ?", [$targetUserId, $channel])
                : null;
            if (!$binding) {
                throw new InvalidArgumentException('Binding not found.');
            }
            channelClearBinding($targetUserId, $channel);
            auditLog('channel_binding_removed', 'user', $targetUserId, (array) $binding, null);
            $flash = ['success', $label . ' alerts disconnected for user #' . $targetUserId . '.'];
        }
    } catch (Throwable $e) {
        $flash = ['danger', $e->getMessage()];
    }
}

$enabled = channelEnabled($channel);
$tokenKey = $channel === 'telegram' ? 'telegram_bot_token' : 'viber_auth_token';
$hasToken = channelConfig($channel, $tokenKey) !== '';
$botUsername = channelConfig('telegram', 'telegram_bot_username');
$senderName = channelConfig('viber', 'viber_sender_name', 'LOKA Fleet');
$webhookSecret = channelConfig($channel, $channel . '_webhook_secret');
$timeout = channelConfig($channel, $channel . '_timeout', '15');
$maxLen = channelConfig($channel, $channel . '_max_length', (string) CHANNEL_DEFAULTS[$channel]['max_length']);
$allowRaw = trim(channelConfig($channel, $channel . '_event_allowlist', $allowlistDefault));
$mirrorEmail = ($allowRaw === '' || $allowRaw === '*');
$allowedEvents = $mirrorEmail
    ? $selectableEvents
    : array_filter(array_map('trim', explode(',', $allowRaw)));
$stats = $queue->getStats($channel);

$logStatus = getSafe('status', '', 20);
$logSearch = getSafe('q', '', 100);
$logDateFrom = getSafe('date_from', '', 20);
$logDateTo = getSafe('date_to', '', 20);
$allowedLogStatuses = ['pending', 'processing', 'sent', 'failed'];
if ($logStatus !== '' && !in_array($logStatus, $allowedLogStatuses, true)) {
    $logStatus = '';
}

$logs = [];
$pag = listPaginationState(0);
$logBaseParams = [
    'page' => 'security',
    'action' => $channel,
    'status' => $logStatus,
    'q' => $logSearch,
    'date_from' => $logDateFrom,
    'date_to' => $logDateTo,
];
try {
    $where = ['s.channel = ?'];
    $params = [$channel];
    if ($logStatus !== '') {
        $where[] = 's.status = ?';
        $params[] = $logStatus;
    }
    if ($logSearch !== '') {
        $where[] = '(s.chat_id LIKE ? OR s.event_type LIKE ? OR s.message LIKE ? OR u.name LIKE ?)';
        $like = '%' . $logSearch . '%';
        $params = array_merge($params, [$like, $like, $like, $like]);
    }
    if ($logDateFrom !== '') {
        $where[] = 's.created_at >= ?';
        $params[] = $logDateFrom . ' 00:00:00';
    }
    if ($logDateTo !== '') {
        $where[] = 's.created_at <= ?';
        $params[] = $logDateTo . ' 23:59:59';
    }
    $whereSql = implode(' AND ', $where);

    $countRow = db()->fetch(
        "SELECT COUNT(*) as c
         FROM channel_logs s
         LEFT JOIN users u ON u.id = s.user_id
         WHERE {$whereSql}",
        $params
    );
    $pag = listPaginationState((int) ($countRow->c ?? 0));
    $logBaseParams['per_page'] = $pag['perPage'];

    $logs = db()->fetchAll(
        "SELECT s.*, u.name AS user_name
         FROM channel_logs s
         LEFT JOIN users u ON u.id = s.user_id
         WHERE {$whereSql}
         ORDER BY s.id DESC
         LIMIT ? OFFSET ?",
        array_merge($params, [$pag['perPage'], $pag['offset']])
    );
} catch (Throwable $e) {
    $flash = $flash ?: ['danger', 'channel_logs missing. Run migration 055 (channel notifications).'];
}

$bindingsCount = (int) db()->fetchColumn(
    "SELECT COUNT(*) FROM user_channel_bindings WHERE channel = ?",
    [$channel]
);

$bindings = db()->fetchAll(
    "SELECT b.*, u.name AS user_name, u.email AS user_email
     FROM user_channel_bindings b
     LEFT JOIN users u ON u.id = b.user_id
     WHERE b.channel = ?
     ORDER BY b.linked_at DESC",
    [$channel]
);

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid px-4 py-4">
    <div class="mb-2">
        <h4 class="mb-1"><i class="bi <?= e($icon) ?> me-2"></i><?= e($label) ?> Notifications</h4>
        <p class="text-muted small mb-0">Soft-fail messenger alerts beside in-app / email / SMS. All Father control. (Plan #35)</p>
    </div>

    <?php require __DIR__ . '/partials/subnav.php'; ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash[0]) ?> mb-4"><?= e($flash[1]) ?></div>
    <?php endif; ?>

    <div class="row g-4 mb-4">
        <div class="col-6 col-md-3">
            <div class="card"><div class="card-body py-3 text-center">
                <div class="fs-4 fw-semibold"><?= $enabled ? 'ON' : 'OFF' ?></div>
                <div class="small text-muted"><?= e($label) ?> enabled</div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card"><div class="card-body py-3 text-center">
                <div class="fs-4 fw-semibold"><?= $bindingsCount ?></div>
                <div class="small text-muted">Linked users</div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card"><div class="card-body py-3 text-center">
                <div class="fs-4 fw-semibold"><?= (int) $stats['pending'] ?></div>
                <div class="small text-muted">Pending</div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card"><div class="card-body py-3 text-center">
                <div class="fs-4 fw-semibold"><?= (int) $stats['sent'] ?></div>
                <div class="small text-muted">Sent</div>
            </div></div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="mb-3">Channel settings</h5>
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="op" value="save_settings">

                        <div class="form-check mb-3">
                            <input type="checkbox" name="<?= e($channel) ?>_enabled" value="1" class="form-check-input" id="chEnabled" <?= $enabled ? 'checked' : '' ?>>
                            <label class="form-check-label" for="chEnabled">Enable <?= e($label) ?> notifications</label>
                        </div>

                        <?php if ($channel === 'telegram'): ?>
                        <div class="mb-3">
                            <label class="form-label">Bot token <span class="text-muted small">(from @BotFather)</span></label>
                            <input type="password" name="telegram_bot_token" class="form-control" value="" autocomplete="new-password"
                                   placeholder="<?= $hasToken ? '•••••••• (unchanged if blank)' : '123456:ABC-DEF...' ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Bot username <span class="text-muted small">(without @ — used for Profile deep links)</span></label>
                            <input type="text" name="telegram_bot_username" class="form-control" value="<?= e($botUsername) ?>" placeholder="LOKAFleetBot">
                        </div>
                        <?php else: ?>
                        <div class="mb-3">
                            <label class="form-label">Viber auth token <span class="text-muted small">(bot / PA account)</span></label>
                            <input type="password" name="viber_auth_token" class="form-control" value="" autocomplete="new-password"
                                   placeholder="<?= $hasToken ? '•••••••• (unchanged if blank)' : 'Required' ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Sender name</label>
                            <input type="text" name="viber_sender_name" class="form-control" value="<?= e($senderName) ?>">
                        </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label">Webhook secret <span class="text-muted small">(optional)</span></label>
                            <input type="text" name="<?= e($channel) ?>_webhook_secret" class="form-control" value="<?= e($webhookSecret) ?>">
                            <p class="form-text small mb-0">Telegram: validated against <code>X-Telegram-Bot-Api-Secret-Token</code>. Viber: required as <code>?key=</code> on the webhook URL when set.</p>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label">Timeout (s)</label>
                                <input type="number" name="<?= e($channel) ?>_timeout" class="form-control" min="5" max="60" value="<?= e($timeout) ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Max length</label>
                                <input type="number" name="<?= e($channel) ?>_max_length" class="form-control" min="80" max="4000" value="<?= e($maxLen) ?>">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label mb-2">Events that may send</label>
                            <div class="form-check border rounded p-2 mb-2">
                                <input type="checkbox" name="mirror_email" value="1" class="form-check-input" id="chMirrorEmail"
                                    <?= $mirrorEmail ? 'checked' : '' ?>
                                    onchange="document.getElementById('chCustomEvents').classList.toggle('d-none', this.checked)">
                                <label class="form-check-label small" for="chMirrorEmail">
                                    <strong>Match all email events</strong> (recommended)
                                </label>
                            </div>
                            <div id="chCustomEvents" class="border rounded p-2 overflow-auto <?= $mirrorEmail ? 'd-none' : '' ?>" style="max-height:12rem;">
                                <?php foreach ($selectableEvents as $ev): ?>
                                    <div class="form-check">
                                        <input type="checkbox" name="events[]" value="<?= e($ev) ?>" class="form-check-input" id="chev_<?= md5($ev) ?>"
                                            <?= in_array($ev, $allowedEvents, true) ? 'checked' : '' ?>>
                                        <label class="form-check-label small" for="chev_<?= md5($ev) ?>"><?= e($ev) ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">Save settings</button>
                    </form>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="mb-3">Test &amp; tools</h5>
                    <form method="POST" class="mb-3">
                        <?= csrfField() ?>
                        <input type="hidden" name="op" value="test_send">
                        <div class="mb-3">
                            <label class="form-label">Test chat ID</label>
                            <input type="text" name="test_chat_id" class="form-control" placeholder="<?= $channel === 'telegram' ? 'Your numeric chat ID (not the bot @username)' : 'Viber member id' ?>" required>
                            <?php if ($channel === 'telegram'): ?>
                            <div class="form-text">Must be <em>your</em> chat id (e.g. <code>123456789</code>), not <code>@<?= e(ltrim((string) channelConfig('telegram', 'telegram_bot_username', 'BotUsername'), '@')) ?></code>. Prefer Profile → Connect → open the bot link → Poll link requests.</div>
                            <?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Message</label>
                            <textarea name="test_message" class="form-control" rows="2">LOKA <?= e($label) ?> test — <?= e(date('Y-m-d H:i')) ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-outline-primary">Send test <?= e($label) ?> message</button>
                    </form>
                    <div class="d-flex flex-wrap gap-2">
                        <form method="POST"><?= csrfField() ?><input type="hidden" name="op" value="process_queue">
                            <button type="submit" class="btn btn-secondary btn-sm">Process queue now</button>
                        </form>
                        <form method="POST"><?= csrfField() ?><input type="hidden" name="op" value="health_check">
                            <button type="submit" class="btn btn-secondary btn-sm">Bot health</button>
                        </form>
                        <?php if ($channel === 'telegram'): ?>
                        <form method="POST"><?= csrfField() ?>
                            <input type="hidden" name="op" value="poll_updates">
                            <input type="hidden" name="offset" value="<?= e(channelConfig('telegram', 'telegram_last_update_id', '0')) ?>">
                            <button type="submit" class="btn btn-secondary btn-sm" title="Redeem pending /start connect codes without a public webhook">Poll link requests</button>
                        </form>
                        <?php else: ?>
                        <form method="POST" onsubmit="return confirm('Register this staging URL as the Viber webhook? Viber will start calling it for conversation events.');"><?= csrfField() ?>
                            <input type="hidden" name="op" value="register_webhook">
                            <button type="submit" class="btn btn-secondary btn-sm" title="Viber has no polling fallback — callbacks only arrive after set_webhook">Register webhook</button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <p class="form-text small mt-3 mb-0">
                        Webhook URL: <code><?= e(rtrim((string) SITE_URL, '/') . ($channel === 'telegram' ? TELEGRAM_WEBHOOK_PATH : VIBER_WEBHOOK_PATH)) ?></code>
                        <?= $channel === 'viber' && $webhookSecret !== '' ? '?key=…' : '' ?><br>
                        Outbound is queued on notify(); drain with <strong>Process queue now</strong>, HTTP cron
                        (<code>?page=cron&amp;action=channels&amp;key=SECRET</code>), or the CLI cron.
                    </p>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card">
                <div class="card-body">
                    <h5 class="mb-3">Recent <?= e($label) ?> logs</h5>

                    <form method="GET" class="d-flex flex-wrap align-items-end gap-2 mb-4">
                        <input type="hidden" name="page" value="security">
                        <input type="hidden" name="action" value="<?= e($channel) ?>">
                        <div class="d-flex flex-column gap-1" style="min-width:140px;">
                            <label class="form-label small fw-semibold text-uppercase text-muted mb-0">Status</label>
                            <select name="status" class="form-select form-select-sm">
                                <option value="">All</option>
                                <?php foreach ($allowedLogStatuses as $st): ?>
                                    <option value="<?= e($st) ?>" <?= $logStatus === $st ? 'selected' : '' ?>><?= e(ucfirst($st)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?= listSearchFieldHtml($logSearch, 'Chat ID, name, event, message…') ?>
                        <div class="d-flex flex-column gap-1">
                            <label class="form-label small fw-semibold text-uppercase text-muted mb-0">From</label>
                            <input type="date" name="date_from" value="<?= e($logDateFrom) ?>" class="form-control form-control-sm">
                        </div>
                        <div class="d-flex flex-column gap-1">
                            <label class="form-label small fw-semibold text-uppercase text-muted mb-0">To</label>
                            <input type="date" name="date_to" value="<?= e($logDateTo) ?>" class="form-control form-control-sm">
                        </div>
                        <?= perPageFieldHtml($pag['perPage']) ?>
                        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                        <a href="<?= APP_URL ?>/?page=security&action=<?= e($channel) ?>" class="btn btn-secondary btn-sm">Reset</a>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-striped table-hover align-middle no-datatable" id="channelLogsTable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>To</th>
                                    <th>Event</th>
                                    <th>Status</th>
                                    <th>Time</th>
                                    <th class="text-center" style="width:4rem;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($logs)): ?>
                                    <tr><td colspan="6" class="text-center text-muted py-4">No <?= e($label) ?> logs match your filters.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($logs as $log): ?>
                                        <tr>
                                            <td><?= (int) $log->id ?></td>
                                            <td class="font-monospace small">
                                                <?= e($log->chat_id) ?>
                                                <?php if (!empty($log->user_name)): ?>
                                                    <div class="text-muted"><?= e($log->user_name) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= e($log->event_type ?: '-') ?>
                                                <div class="small text-muted text-truncate" style="max-width:18rem;" title="<?= e($log->message) ?>"><?= e($log->message) ?></div>
                                                <?php if ($log->status === 'failed' && $log->error_message): ?>
                                                    <div class="small text-danger"><?= e($log->error_message) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                if ($log->status === 'sent') {
                                                    $stClass = 'bg-success';
                                                } elseif ($log->status === 'failed') {
                                                    $stClass = 'bg-danger';
                                                } elseif ($log->status === 'processing') {
                                                    $stClass = 'bg-info';
                                                } else {
                                                    $stClass = 'bg-warning text-dark';
                                                }
                                                ?>
                                                <span class="badge <?= $stClass ?>"><?= e($log->status) ?></span>
                                            </td>
                                            <td class="small text-nowrap"><?= e($log->created_at) ?></td>
                                            <td class="text-center">
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete log #<?= (int) $log->id ?>? This cannot be undone.');">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="op" value="delete_log">
                                                    <input type="hidden" name="log_id" value="<?= (int) $log->id ?>">
                                                    <input type="hidden" name="ret_status" value="<?= e($logStatus) ?>">
                                                    <input type="hidden" name="ret_q" value="<?= e($logSearch) ?>">
                                                    <input type="hidden" name="ret_date_from" value="<?= e($logDateFrom) ?>">
                                                    <input type="hidden" name="ret_date_to" value="<?= e($logDateTo) ?>">
                                                    <input type="hidden" name="ret_per_page" value="<?= (int) $pag['perPage'] ?>">
                                                    <input type="hidden" name="ret_p" value="<?= (int) $pag['page'] ?>">
                                                    <button type="submit" class="btn btn-link btn-sm text-danger p-0" title="Delete log">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?= listPaginationFooter($pag, $logBaseParams) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-body">
            <h5 class="mb-3">Linked users <span class="badge bg-secondary"><?= count($bindings) ?></span></h5>
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle no-datatable" id="channelBindingsTable">
                    <thead>
                        <tr>
                            <th>User ID</th>
                            <th>User</th>
                            <th>Chat ID</th>
                            <th>Linked via</th>
                            <th>Linked at</th>
                            <th class="text-center" style="width:4rem;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($bindings)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No users have linked <?= e($label) ?> yet. Users link via Profile → Messenger Alerts → Connect.</td></tr>
                        <?php else: ?>
                            <?php foreach ($bindings as $bd): ?>
                                <tr>
                                    <td><?= (int) $bd->user_id ?></td>
                                    <td>
                                        <?= e($bd->user_name ?? ('User #' . (int) $bd->user_id)) ?>
                                        <?php if (!empty($bd->user_email)): ?>
                                            <div class="small text-muted"><?= e($bd->user_email) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($bd->display_name)): ?>
                                            <div class="small text-muted"><?= e($bd->display_name) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="font-monospace small"><?= e($bd->chat_id) ?></td>
                                    <td class="small"><?= e($bd->linked_via === 'admin' ? 'administrator' : 'self-link') ?></td>
                                    <td class="small text-nowrap"><?= e($bd->linked_at) ?></td>
                                    <td class="text-center">
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Disconnect <?= e($label) ?> alerts for user #<?= (int) $bd->user_id ?>?');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="op" value="unlink_user">
                                            <input type="hidden" name="target_user_id" value="<?= (int) $bd->user_id ?>">
                                            <button type="submit" class="btn btn-link btn-sm text-danger p-0" title="Disconnect user">
                                                <i class="bi bi-person-x"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
