<?php
/**
 * All Father — GPS Tracking hub (Plan #41, experimental)
 *
 * Toggle + a privacy/scoped summary of what v1 does and does not collect.
 */

require_once INCLUDES_PATH . '/gps_tracking.php';
requireSystemControl();

$pageTitle = 'GPS Tracking';
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $op = postSafe('op', '', 20);
    if ($op === 'toggle') {
        gpsTrackingSetEnabled(post('enabled', '0') === '1');
        redirectWith(
            '/?page=security&action=gps-tracking',
            'success',
            gpsTrackingEnabled()
                ? 'GPS trip tracking is ON. Drivers of dispatched fleet trips will be offered the Trip Tracking page.'
                : 'GPS trip tracking is OFF. No pings are accepted and nothing is shown to operators.'
        );
    }
    if ($op === 'purge') {
        $r = processGpsRetentionOnce();
        redirectWith('/?page=security&action=gps-tracking', 'success', 'Purged {$r} point(s) older than ' . GPS_RETENTION_DAYS . ' days.');
    }
}

/** Local purge (kept here so the hub does not have to require the cron file). */
function processGpsRetentionOnce(): int
{
    if (!gpsTrackingEnabled()) {
        return 0;
    }
    return gpsPurgeOldPoints(GPS_RETENTION_DAYS)['purged'];
}

$enabled = gpsTrackingEnabled();
$stats = ['points' => 0, 'trips' => 0, 'oldest' => null];
try {
    $row = db()->fetch(
        "SELECT COUNT(*) AS points, COUNT(DISTINCT request_id) AS trips, MIN(received_at) AS oldest
         FROM trip_gps_points"
    );
    if ($row) {
        $stats = [
            'points' => (int) $row->points,
            'trips' => (int) $row->trips,
            'oldest' => $row->oldest,
        ];
    }
} catch (Throwable $e) {
    error_log('gps stats: ' . $e->getMessage());
}

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid px-4 py-4">
    <div class="mb-2">
        <h4 class="mb-1"><i class="bi bi-geo-alt me-2"></i>GPS Tracking</h4>
        <p class="text-muted small mb-0">
            Experimental (Plan #41), ships <strong>off</strong>. Drivers' phones share their
            position while a dispatched fleet trip is under way.
        </p>
    </div>

    <?php require __DIR__ . '/partials/subnav.php'; ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash[0]) ?>"><?= e($flash[1]) ?></div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            <div class="me-auto">
                <div class="fw-semibold">
                    <?= $enabled
                        ? '<span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>Enabled</span>'
                        : '<span class="text-secondary"><i class="bi bi-slash-circle me-1"></i>Disabled</span>' ?>
                </div>
                <div class="small text-muted">
                    When off: <code>?page=gps-tracking</code> redirects, the ping endpoint returns 403,
                    the operator panel is not rendered, and the retention job deletes nothing.
                </div>
            </div>
            <form method="POST" onsubmit="return confirm('Change the GPS tracking feature switch?');">
                <?= csrfField() ?>
                <input type="hidden" name="op" value="toggle">
                <input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
                <button type="submit" class="btn btn-<?= $enabled ? 'outline-danger' : 'primary' ?>">
                    <i class="bi bi-power me-1"></i><?= $enabled ? 'Disable' : 'Enable' ?>
                </button>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card"><div class="card-body py-3 text-center">
                <div class="fs-4 fw-semibold"><?= (int) $stats['points'] ?></div>
                <div class="small text-muted">Points stored</div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card"><div class="card-body py-3 text-center">
                <div class="fs-4 fw-semibold"><?= (int) $stats['trips'] ?></div>
                <div class="small text-muted">Trips tracked</div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card"><div class="card-body py-3 text-center">
                <div class="fs-4 fw-semibold"><?= GPS_RETENTION_DAYS ?></div>
                <div class="small text-muted">Day retention</div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card"><div class="card-body py-3 text-center">
                <div class="small text-muted">Oldest point</div>
                <div class="small fw-semibold"><?= $stats['oldest'] ? e(formatDate($stats['oldest'])) : '—' ?></div>
            </div></div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Scope and privacy (locked for v1)</h5></div>
        <div class="card-body">
            <div class="row g-4">
                <div class="col-md-6">
                    <h6>What is collected</h6>
                    <ul class="mb-0 small">
                        <li>Latitude, longitude, accuracy, speed and heading — from the driver's own phone, while the page is open.</li>
                        <li>Only for an <strong>approved, dispatched</strong> request where the caller is the assigned driver and a fleet vehicle is attached.</li>
                        <li>Nothing else: no device identifiers, no contacts, no background location.</li>
                    </ul>
                    <h6 class="mt-3">Retention</h6>
                    <ul class="mb-0 small">
                        <li>Points are purged after <?= GPS_RETENTION_DAYS ?> days by <code>cron/process_gps_retention.php</code>.</li>
                        <li>Purge is a no-op while the feature is off, so stored history is never silently dropped.</li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <h6>Who can see it</h6>
                    <ul class="mb-0 small">
                        <li><strong>Motorpool Head, Admin and All Father</strong> — on the Live Trip Board.</li>
                        <li>Exact coordinates are shown to those roles only. The requester, the department approver and guards cannot see the panel.</li>
                        <li>Trails are drawn on a bare SVG grid, so no map-tile provider ever receives the vehicle's coordinates.</li>
                    </ul>
                    <h6 class="mt-3">Not in v1</h6>
                    <ul class="mb-0 small">
                        <li>Native iOS/Android background tracking, MDM, hardware trackers.</li>
                        <li>Private-vehicle OB cars.</li>
                        <li>Driver pause/start — the window is opened by guard Dispatch and closed by guard Arrival.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <?php if ($enabled): ?>
        <form method="POST" onsubmit="return confirm('Delete stored points older than <?= GPS_RETENTION_DAYS ?> days?');">
            <?= csrfField() ?>
            <input type="hidden" name="op" value="purge">
            <button type="submit" class="btn btn-outline-secondary">
                <i class="bi bi-trash me-1"></i>Purge points older than <?= GPS_RETENTION_DAYS ?> days
            </button>
        </form>
    <?php endif; ?>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>