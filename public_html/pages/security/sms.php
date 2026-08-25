<?php
/**
 * All Father — SMS notifications settings & logs
 */

requireSystemControl();

require_once CONFIG_PATH . '/sms.php';
require_once INCLUDES_PATH . '/sms.php';
require_once BASE_PATH . '/classes/SmsGateway.php';
require_once BASE_PATH . '/classes/SmsQueue.php';

$pageTitle = 'SMS Notifications';
$flash = null;
$queue = new SmsQueue();

$selectableEvents = smsSelectableEvents();
$allowlistDefault = '*';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $op = post('op', '');

    try {
        if ($op === 'save_settings') {
            $enabled = post('sms_enabled', '0') === '1' ? '1' : '0';
            $url = trim(postSafe('sms_gateway_url', '', 255));
            $username = trim(postSafe('sms_gateway_username', '', 100));
            $password = (string) post('sms_gateway_password', '');
            $apiPath = trim(postSafe('sms_api_path', SMS_API_PATH_CLOUD, 120));
            $country = preg_replace('/\D+/', '', postSafe('sms_country_code', '63', 5)) ?: '63';
            $timeout = max(5, min(60, (int) post('sms_timeout_seconds', 15)));
            $maxLen = max(80, min(1600, (int) post('sms_max_length', 320)));

            $mirrorEmail = post('sms_mirror_email', '0') === '1';
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

            $allowedPaths = [SMS_API_PATH_LOCAL, SMS_API_PATH_PRIVATE, SMS_API_PATH_CLOUD];
            if (!in_array($apiPath, $allowedPaths, true)) {
                $apiPath = SMS_API_PATH_CLOUD;
            }

            smsSaveSetting('sms_enabled', $enabled, 'boolean');
            smsSaveSetting('sms_gateway_url', $url);
            smsSaveSetting('sms_gateway_username', $username);
            if ($password !== '') {
                smsSaveSetting('sms_gateway_password', $password);
            }
            smsSaveSetting('sms_api_path', $apiPath !== '' ? $apiPath : SMS_API_PATH_CLOUD);
            smsSaveSetting('sms_country_code', $country);
            smsSaveSetting('sms_timeout_seconds', (string) $timeout, 'integer');
            smsSaveSetting('sms_max_length', (string) $maxLen, 'integer');
            smsSaveSetting('sms_event_allowlist', $allowlist);
            smsConfigClearCache();

            auditLog('sms_settings_updated', 'settings', null, null, [
                'enabled' => $enabled,
                'gateway_url' => $url,
                'api_path' => $apiPath,
            ]);
            $flash = ['success', 'SMS settings saved.'];
        } elseif ($op === 'test_send') {
            if (!smsEnabled()) {
                throw new RuntimeException('Enable SMS notifications before sending a test.');
            }
            $phone = trim(postSafe('test_phone', '', 30));
            $msg = trim(postSafe('test_message', 'LOKA SMS test — ' . date('Y-m-d H:i'), 320));
            $id = $queue->queueTest($phone, $msg, userId());
            $processed = $queue->process(1);
            $row = $id ? db()->fetch("SELECT status, error_message FROM sms_logs WHERE id = ?", [$id]) : null;
            if ($row && $row->status === 'sent') {
                $flash = ['success', 'Test SMS sent successfully.'];
            } elseif ($row && $row->status === 'pending') {
                $flash = ['warning', 'Test queued (pending). Run Process queue or wait for cron. Sent=' . $processed['sent']];
            } else {
                $err = $row->error_message ?? 'Unknown error';
                $flash = ['danger', 'Test SMS failed: ' . $err];
            }
        } elseif ($op === 'process_queue') {
            $r = $queue->process(30);
            $flash = ['success', "Processed queue: sent {$r['sent']}, failed {$r['failed']}, skipped {$r['skipped']}."];
        } elseif ($op === 'health_check') {
            $gw = SmsGateway::fromConfig();
            if (!$gw) {
                $flash = ['danger', 'Gateway URL/username/password not configured.'];
            } else {
                $h = $gw->health();
                $flash = $h['ok']
                    ? ['success', 'Gateway health OK (HTTP ' . $h['http_code'] . ').']
                    : ['danger', 'Gateway health failed: ' . ($h['error'] ?: 'unknown')];
            }
        } elseif ($op === 'delete_log') {
            $logId = postInt('log_id');
            $row = $logId
                ? db()->fetch("SELECT id, phone, event_type, status FROM sms_logs WHERE id = ?", [$logId])
                : null;
            if (!$row) {
                throw new InvalidArgumentException('SMS log not found.');
            }
            db()->delete('sms_logs', 'id = ?', [$logId]);
            auditLog('sms_log_deleted', 'sms_log', $logId, (array) $row, null);
            $qs = http_build_query(array_filter([
                'page' => 'security',
                'action' => 'sms',
                'status' => postSafe('ret_status', '', 20),
                'q' => postSafe('ret_q', '', 100),
                'date_from' => postSafe('ret_date_from', '', 20),
                'date_to' => postSafe('ret_date_to', '', 20),
                'per_page' => postSafe('ret_per_page', '', 10),
                'p' => postSafe('ret_p', '', 10),
            ], static fn($v) => $v !== null && $v !== ''));
            redirectWith('/?' . $qs, 'success', "SMS log #{$logId} deleted.");
        }
    } catch (Throwable $e) {
        $flash = ['danger', $e->getMessage()];
    }
}

$enabled = smsEnabled();
$gatewayUrl = smsConfig('sms_gateway_url');
$gatewayUser = smsConfig('sms_gateway_username');
$hasPassword = smsConfig('sms_gateway_password') !== '';
$apiPath = smsConfig('sms_api_path', SMS_API_PATH_LOCAL);
$country = smsConfig('sms_country_code', '63');
$timeout = smsConfig('sms_timeout_seconds', '15');
$maxLen = smsConfig('sms_max_length', '320');
$allowRaw = trim(smsConfig('sms_event_allowlist', $allowlistDefault));
$mirrorEmail = ($allowRaw === '' || $allowRaw === '*');
$allowedEvents = $mirrorEmail
    ? $selectableEvents
    : array_filter(array_map('trim', explode(',', $allowRaw)));
$stats = $queue->getStats();

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
    'action' => 'sms',
    'status' => $logStatus,
    'q' => $logSearch,
    'date_from' => $logDateFrom,
    'date_to' => $logDateTo,
];
try {
    $where = ['1=1'];
    $params = [];
    if ($logStatus !== '') {
        $where[] = 's.status = ?';
        $params[] = $logStatus;
    }
    if ($logSearch !== '') {
        $where[] = '(s.phone LIKE ? OR s.event_type LIKE ? OR s.message LIKE ? OR u.name LIKE ?)';
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
         FROM sms_logs s
         LEFT JOIN users u ON u.id = s.user_id
         WHERE {$whereSql}",
        $params
    );
    $pag = listPaginationState((int) ($countRow->c ?? 0));
    $logBaseParams['per_page'] = $pag['perPage'];

    $logs = db()->fetchAll(
        "SELECT s.*, u.name AS user_name
         FROM sms_logs s
         LEFT JOIN users u ON u.id = s.user_id
         WHERE {$whereSql}
         ORDER BY s.id DESC
         LIMIT ? OFFSET ?",
        array_merge($params, [$pag['perPage'], $pag['offset']])
    );
} catch (Throwable $e) {
    $flash = $flash ?: ['danger', 'SMS tables missing. Run migration 034 (sms notifications).'];
}

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid px-4 py-4">
    <div class="mb-2">
        <h4 class="mb-1">SMS Notifications</h4>
        <p class="text-muted small mb-0">Outbound notify-only for travel participants. All Father control.</p>
    </div>

    <?php require __DIR__ . '/partials/subnav.php'; ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash[0]) ?> mb-4"><?= e($flash[1]) ?></div>
    <?php endif; ?>

    <div class="row g-4 mb-4">
        <div class="col-6 col-md-3">
            <div class="card"><div class="card-body py-3 text-center">
                <div class="fs-4 fw-semibold"><?= $enabled ? 'ON' : 'OFF' ?></div>
                <div class="small text-muted">SMS enabled</div>
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
        <div class="col-6 col-md-3">
            <div class="card"><div class="card-body py-3 text-center">
                <div class="fs-4 fw-semibold"><?= (int) $stats['failed'] ?></div>
                <div class="small text-muted">Failed</div>
            </div></div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="mb-3">Gateway settings</h5>
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="op" value="save_settings">

                        <div class="form-check mb-3">
                            <input type="checkbox" name="sms_enabled" value="1" class="form-check-input" id="smsEnabled" <?= $enabled ? 'checked' : '' ?>>
                            <label class="form-check-label" for="smsEnabled">Enable SMS notifications</label>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Gateway URL</label>
                            <input type="text" name="sms_gateway_url" class="form-control"
                                   placeholder="http://192.168.x.x:8080 or https://sms.yourdomain.com"
                                   value="<?= e($gatewayUrl) ?>">
                            <p class="form-text small mb-0">Local phone server: device LAN IP + port 8080. Private server: HTTPS base URL.</p>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label">Username</label>
                                <input type="text" name="sms_gateway_username" class="form-control" value="<?= e($gatewayUser) ?>" autocomplete="off">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Password</label>
                                <input type="password" name="sms_gateway_password" class="form-control" value="" autocomplete="new-password"
                                       placeholder="<?= $hasPassword ? '•••••••• (unchanged if blank)' : 'Required' ?>">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">API path</label>
                            <select name="sms_api_path" class="form-select">
                                <option value="<?= e(SMS_API_PATH_CLOUD) ?>" <?= $apiPath === SMS_API_PATH_CLOUD ? 'selected' : '' ?>>
                                    Public cloud sms-gate.app (<?= e(SMS_API_PATH_CLOUD) ?>)
                                </option>
                                <option value="<?= e(SMS_API_PATH_LOCAL) ?>" <?= $apiPath === SMS_API_PATH_LOCAL ? 'selected' : '' ?>>
                                    Local phone server (<?= e(SMS_API_PATH_LOCAL) ?>)
                                </option>
                                <option value="<?= e(SMS_API_PATH_PRIVATE) ?>" <?= $apiPath === SMS_API_PATH_PRIVATE ? 'selected' : '' ?>>
                                    Self-hosted private server (<?= e(SMS_API_PATH_PRIVATE) ?>)
                                </option>
                            </select>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-4">
                                <label class="form-label">Country code</label>
                                <input type="text" name="sms_country_code" class="form-control" value="<?= e($country) ?>">
                            </div>
                            <div class="col-4">
                                <label class="form-label">Timeout (s)</label>
                                <input type="number" name="sms_timeout_seconds" class="form-control" min="5" max="60" value="<?= e($timeout) ?>">
                            </div>
                            <div class="col-4">
                                <label class="form-label">Max length</label>
                                <input type="number" name="sms_max_length" class="form-control" min="80" max="1600" value="<?= e($maxLen) ?>">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label mb-2">Events that may SMS</label>
                            <div class="form-check border rounded p-2 mb-2">
                                <input type="checkbox" name="sms_mirror_email" value="1" class="form-check-input form-check-input-sm" id="smsMirrorEmail"
                                    <?= $mirrorEmail ? 'checked' : '' ?>
                                    onchange="document.getElementById('smsCustomEvents').classList.toggle('d-none', this.checked)">
                                <label class="form-check-label small" for="smsMirrorEmail">
                                    <strong>Match all email events</strong> (recommended — same types as email notifications)
                                </label>
                            </div>
                            <div id="smsCustomEvents" class="border rounded p-2 overflow-auto <?= $mirrorEmail ? 'd-none' : '' ?>" style="max-height:12rem;">
                                <?php foreach ($selectableEvents as $ev): ?>
                                    <div class="form-check">
                                        <input type="checkbox" name="events[]" value="<?= e($ev) ?>" class="form-check-input" id="ev_<?= md5($ev) ?>"
                                            <?= in_array($ev, $allowedEvents, true) ? 'checked' : '' ?>>
                                        <label class="form-check-label small" for="ev_<?= md5($ev) ?>"><?= e($ev) ?></label>
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
                            <label class="form-label">Test phone</label>
                            <input type="text" name="test_phone" class="form-control" placeholder="09XXXXXXXXX" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Message</label>
                            <textarea name="test_message" class="form-control" rows="2">LOKA SMS test — <?= e(date('Y-m-d H:i')) ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-outline-primary">Send test SMS</button>
                    </form>
                    <div class="d-flex flex-wrap gap-2">
                        <form method="POST"><?= csrfField() ?><input type="hidden" name="op" value="process_queue">
                            <button type="submit" class="btn btn-secondary btn-sm">Process queue now</button>
                        </form>
                        <form method="POST"><?= csrfField() ?><input type="hidden" name="op" value="health_check">
                            <button type="submit" class="btn btn-secondary btn-sm">Gateway health</button>
                        </form>
                    </div>
                    <p class="form-text small mt-3 mb-0">
                        Local tip: enable Local Server in the SMS Gateway Android app, use API path “Local phone server”,
                        Gateway URL = <code>http://PHONE_LAN_IP:8080</code>, credentials shown in the app.
                        Outbound SMS is queued on submit (does not wait on the gateway); use <strong>Process queue now</strong>
                        or HTTP/CLI cron to send pending messages.
                    </p>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card">
                <div class="card-body">
                    <h5 class="mb-3">Recent SMS logs</h5>

                    <form method="GET" class="d-flex flex-wrap align-items-end gap-2 mb-4">
                        <input type="hidden" name="page" value="security">
                        <input type="hidden" name="action" value="sms">
                        <div class="d-flex flex-column gap-1" style="min-width:140px;">
                            <label class="form-label small fw-semibold text-uppercase text-muted mb-0">Status</label>
                            <select name="status" class="form-select form-select-sm">
                                <option value="">All</option>
                                <?php foreach ($allowedLogStatuses as $st): ?>
                                    <option value="<?= e($st) ?>" <?= $logStatus === $st ? 'selected' : '' ?>><?= e(ucfirst($st)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?= listSearchFieldHtml($logSearch, 'Phone, name, event, message…') ?>
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
                        <a href="<?= APP_URL ?>/?page=security&action=sms" class="btn btn-secondary btn-sm">Reset</a>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-striped table-hover align-middle" id="smsLogsTable">
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
                                    <tr><td colspan="6" class="text-center text-muted py-4">No SMS logs match your filters.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($logs as $log): ?>
                                        <tr class="sms-log-row" tabindex="0" data-log-id="<?= (int) $log->id ?>">
                                            <td><?= (int) $log->id ?></td>
                                            <td class="font-monospace small">
                                                <?= e($log->phone) ?>
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
                                            <td class="text-center" onclick="event.stopPropagation()">
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete SMS log #<?= (int) $log->id ?>? This cannot be undone.');">
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
</div>

<script>
(function () {
    const table = document.getElementById('smsLogsTable');
    if (!table) return;
    table.querySelectorAll('tr.sms-log-row').forEach(function (row) {
        row.addEventListener('click', function (e) {
            if (e.target.closest('button, a, form, input, select, textarea, label')) return;
            const was = row.classList.contains('table-active');
            table.querySelectorAll('tr.sms-log-row.table-active').forEach(function (r) {
                r.classList.remove('table-active');
            });
            if (!was) row.classList.add('table-active');
        });
        row.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                row.click();
            }
        });
    });
})();
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
