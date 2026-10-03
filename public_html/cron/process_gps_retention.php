<?php
/**
 * LOKA - GPS breadcrumb retention (Plan #41)
 *
 * Deletes trip_gps_points older than GPS_RETENTION_DAYS (about 30). Runs from
 * the CLI cron, the HTTP cron (?page=cron&action=gps&key=SECRET) and — because it
 * is cheap and idempotent — opportunistically from the ping endpoint.
 */

if (php_sapi_name() === 'cli') {
    require_once __DIR__ . '/../config/bootstrap.php';
}

if (!function_exists('gpsPurgeOldPoints')) {
    require_once BASE_PATH . '/includes/gps_tracking.php';
}

/**
 * @return array{purged:int}
 */
function processGpsRetention(): array
{
    // Only purge while the feature is on, so a switched-off module never has its
    // stored history silently deleted out from under an operator.
    if (!gpsTrackingEnabled()) {
        return ['purged' => 0];
    }
    return gpsPurgeOldPoints(GPS_RETENTION_DAYS);
}

if (php_sapi_name() === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $r = processGpsRetention();
    echo date('c') . " GPS retention purged={$r['purged']}\n";
}