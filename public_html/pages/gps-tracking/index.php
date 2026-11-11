<?php
/**
 * LOKA - Driver Trip Tracking (Plan #41, experimental)
 *
 * Route: ?page=gps-tracking — only the assigned driver of a live, dispatched
 * fleet trip can use it. Consent is captured once per driver (session), then the
 * page polls the browser's Geolocation API while it stays open and posts each fix
 * to ?page=api&action=gps_ping.
 *
 * Foreground only (Plan #41 decision 3): if the tab closes or the phone locks,
 * the ops map simply shows the last-seen position.
 */

require_once INCLUDES_PATH . '/gps_tracking.php';

if (!gpsTrackingEnabled()) {
    redirectWith('/?page=my-trips', 'warning', 'GPS trip tracking is switched off.');
}

$driverRecordId = currentDriverId();
if (!$driverRecordId) {
    redirectWith('/?page=my-trips', 'danger', 'GPS trip tracking is only available to drivers.');
}

$trip = gpsTrackingCurrentTripForDriver($driverRecordId);

// ---- consent (Plan #41 decision 7) -----------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('op') === 'consent') {
    requireCsrf();
    if (trim(postSafe('consent', '', 20)) !== 'agree') {
        redirectWith('/?page=gps-tracking', 'info', 'Location sharing was not enabled.');
    }
    $_SESSION['gps_consent_at'] = date(DATETIME_FORMAT);
    auditLog('gps_tracking_consent', 'user', userId(), null, ['trip_id' => $trip ? (int) $trip->id : null]);
    redirectWith('/?page=gps-tracking', 'success', 'Location sharing enabled for this trip.');
}

$consented = !empty($_SESSION['gps_consent_at']);
$pageTitle = 'Trip Tracking';
require_once INCLUDES_PATH . '/header.php';
?>

<div class="container py-4">
    <div class="d-flex flex-wrap align-items-start gap-3 mb-4">
        <div class="me-auto">
            <h4 class="mb-1"><i class="bi bi-geo-alt me-2"></i>Trip Tracking</h4>
            <p class="text-muted mb-0 small">
                Experimental (Plan #41). Your location is shared only while the guard has
                recorded your dispatch, and Motorpool / Admin / All Father can see it.
            </p>
        </div>
        <span class="badge bg-<?= gpsTrackingEnabled() ? 'success' : 'secondary' ?>">
            <?= gpsTrackingEnabled() ? 'Tracking ON' : 'Tracking OFF' ?>
        </span>
    </div>

    <?php if (!$trip): ?>
        <div class="alert alert-secondary d-flex align-items-start" role="alert">
            <i class="bi bi-info-circle-fill flex-shrink-0 me-2"></i>
            <div>
                <strong>Nothing to track right now.</strong>
                The tracking window opens when the guard records your dispatch and closes
                when they record your arrival. It also needs an approved request with a
                fleet vehicle and you as the assigned driver.
            </div>
        </div>
        <a class="btn btn-outline-secondary" href="<?= APP_URL ?>/?page=my-trips">
            <i class="bi bi-arrow-left me-1"></i>Back to my trips
        </a>
    <?php else: ?>
        <div class="card mb-4">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-4">
                        <label class="small text-muted">Request</label>
                        <div class="fw-semibold">#<?= (int) $trip->id ?></div>
                    </div>
                    <div class="col-sm-4">
                        <label class="small text-muted">Vehicle</label>
                        <div class="fw-semibold"><?= e($trip->plate_number ?? '—') ?></div>
                    </div>
                    <div class="col-sm-4">
                        <label class="small text-muted">Dispatched</label>
                        <div class="fw-semibold"><?= e(formatDateTime($trip->actual_dispatch_datetime)) ?></div>
                    </div>
                    <div class="col-12">
                        <label class="small text-muted">Destination</label>
                        <div><?= e($trip->destination) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!$consented): ?>
            <div class="card border-warning mb-4">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0"><i class="bi bi-shield-exclamation me-2"></i>Before you start</h5>
                </div>
                <div class="card-body">
                    <ul class="mb-3">
                        <li>Your phone will ask for location permission — choose <strong>Allow</strong>.</li>
                        <li>Sharing stops by itself when the guard records your arrival, or if you close this page.</li>
                        <li>Your position is visible to Motorpool, Admin and All Father only. Nobody else — not the requester, not your approver.</li>
                        <li>Points are kept for about <?= GPS_RETENTION_DAYS ?> days and then deleted.</li>
                        <li>Keep this page open (or install the site to your home screen) while you drive. A locked phone will not keep sending.</li>
                    </ul>
                    <form method="POST" onsubmit="return confirm('Share your location for this trip?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="op" value="consent">
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="consent" name="consent" value="agree" required>
                            <label class="form-check-label" for="consent">
                                I agree to share my location for this trip.
                            </label>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Agree and start tracking
                        </button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-flex flex-wrap align-items-center gap-3">
                        <div class="me-auto">
                            <div class="fw-semibold" id="gpsState">Ready to share</div>
                            <div class="small text-muted" id="gpsDetail">
                                Keep this tab open while you drive.
                            </div>
                        </div>
                        <span class="badge bg-light text-muted border" id="gpsSent">0 sent</span>
                    </div>
                    <div class="progress mt-3" style="height:6px;">
                        <div class="progress-bar" id="gpsBar" style="width:0%"></div>
                    </div>
                    <p class="small text-muted mt-3 mb-0">
                        Pings are throttled to roughly one every <?= (int) (GPS_MIN_PING_INTERVAL_SECONDS / 5) * 5 ?> seconds
                        and fixes worse than <?= GPS_POOR_ACCURACY_METERS ?>&nbsp;m accuracy are dropped to save battery.
                    </p>
                </div>
            </div>
            <a class="btn btn-outline-secondary" href="<?= APP_URL ?>/?page=my-trips">
                <i class="bi bi-arrow-left me-1"></i>Back to my trips
            </a>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($trip && $consented): ?>
<script>
(function () {
    'use strict';

    var ENDPOINT = (window.LOKA_APP_URL || '') + '/?page=api&action=gps_ping';
    var CSRF = window.LOKA_CSRF_TOKEN || '';
    var MIN_GAP_MS = <?= GPS_MIN_PING_INTERVAL_SECONDS ?> * 1000;
    var BAD_ACCURACY_M = <?= GPS_POOR_ACCURACY_METERS ?>;

    var state = document.getElementById('gpsState');
    var detail = document.getElementById('gpsDetail');
    var sent = document.getElementById('gpsSent');
    var bar = document.getElementById('gpsBar');

    if (!state) return;

    var count = 0;
    var lastSentAt = 0;
    var watching = false;
    var watchId = null;
    var blockedOnce = false;

    function say(headline, detailText, pct) {
        state.textContent = headline;
        detail.textContent = detailText;
        bar.style.width = Math.max(0, Math.min(100, pct)) + '%';
    }

    var QUEUE_KEY = 'lokaGpsQueue';

    function queueLoad() {
        try { return JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]') || []; } catch (e) { return []; }
    }
    function queueSave(q) {
        try { localStorage.setItem(QUEUE_KEY, JSON.stringify(q.slice(-50))); } catch (e) { /* storage blocked — drop silently */ }
    }
    function queuePush(body) { var q = queueLoad(); q.push(body); queueSave(q); }

    function sendBody(body, onNetFail) {
        return fetch(ENDPOINT, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (r) {
            return r.json().catch(function () { return {}; });
        }).then(function (j) {
            if (j.ok) {
                count++;
                sent.textContent = count + ' sent';
                say('Sharing your location', 'Last sent just now · trip #' + (j.request_id || ''), 100);
            } else if (j.inactive) {
                stop('Trip closed', 'The trip finished, so tracking has stopped.');
            } else if (j.skipped) {
                say('Sharing your location', 'Waiting — ' + j.reason, 60);
            } else if (j.disabled) {
                stop('Tracking switched off', 'An All Father has switched GPS tracking off.');
            } else {
                say('Problem', j.error || 'The server refused that position.', 0);
            }
        }).catch(function () {
            // Decision 7: keep the last-good fix locally and flush on reconnect.
            if (onNetFail) {
                onNetFail();
                say('Offline', 'Position queued — it will send when you are back online.', 0);
            }
        });
    }

    function flushQueue() {
        var q = queueLoad();
        if (!q.length) return;
        queueSave([]);
        q.forEach(function (body) {
            sendBody(body, function () { queuePush(body); });
        });
    }

    window.addEventListener('online', function () {
        flushQueue();
        say('Back online', 'Sending queued positions…', 100);
    });

    function post(fix) {
        var body = {
            csrf_token: CSRF,
            lat: fix.coords.latitude,
            lng: fix.coords.longitude,
            accuracy: fix.coords.accuracy,
            speed: fix.coords.speed,
            heading: fix.coords.heading,
            recorded_at: Math.floor(fix.timestamp / 1000)
        };
        sendBody(body, function () { queuePush(body); });
    }

    function stop(headline, detailText) {
        watching = false;
        say(headline, detailText, 0);
    }

    function onPosition(fix) {
        if (!watching) return;
        var now = Date.now();
        var gap = now - lastSentAt;
        var acc = fix.coords.accuracy || 0;

        if (acc > BAD_ACCURACY_M) {
            say('Weak signal', 'Accuracy is ' + Math.round(acc) + ' m — waiting for a better fix.', 20);
            return;
        }
        if (gap < MIN_GAP_MS) {
            var pct = Math.round((gap / MIN_GAP_MS) * 100);
            say('Sharing your location', 'Next ping in ' + Math.ceil((MIN_GAP_MS - gap) / 1000) + 's', pct);
            return;
        }
        lastSentAt = now;
        post(fix);
    }

    function onError(err) {
        if (!watching) return;
        if (err && err.code === 1) {
            // Android can flip location off behind the driver's back (battery
            // saver, per-app permission, screen lock). Do NOT dead-end: keep
            // the watch alive and self-heal when the Permissions API says it
            // returned to granted (see the permission watcher below).
            blockedOnce = true;
            say('Location blocked', 'Tap the padlock in the address bar > Permissions > Location > Allow. If it still fails: Android Settings > Apps > your browser > Permissions > Location.', 0);
        } else if (err && err.code === 3) {
            say('No fix yet', 'Waiting for a GPS signal…', 10);
        } else {
            say('Location unavailable', 'Motorpool sees your last recorded position.', 0);
        }
    }

    function beginWatch() {
        watchId = navigator.geolocation.watchPosition(onPosition, onError, {
            enableHighAccuracy: true,
            maximumAge: 15000,
            timeout: 30000
        });
    }

    // When the block is lifted mid-trip (driver re-allows, battery saver
    // releases location), restart the watch without a page reload.
    if (navigator.permissions && navigator.permissions.query) {
        try {
            navigator.permissions.query({ name: 'geolocation' }).then(function (p) {
                p.onchange = function () {
                    if (p.state === 'granted' && watching && blockedOnce) {
                        blockedOnce = false;
                        say('Sharing your location', 'Location allowed again — waiting for a fresh fix.', 30);
                        if (watchId !== null) {
                            navigator.geolocation.clearWatch(watchId);
                        }
                        beginWatch();
                    }
                };
            }).catch(function () { /* Permissions API unavailable — rely on onError */ });
        } catch (e) { /* older browser — rely on onError */ }
    }

    function start() {
        if (watching) return;
        if (!navigator.geolocation) {
            say('Not supported', 'This browser does not provide location.', 0);
            return;
        }
        watching = true;
        say('Starting…', 'Waiting for the first GPS fix.', 10);
        beginWatch();
    }

    start();
})();
</script>
<?php endif; ?>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>