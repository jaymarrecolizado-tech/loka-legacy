<?php
/**
 * LOKA - GPS ping intake (Plan #41, experimental)
 *
 * POST ?page=api&action=gps_ping
 *   {lat, lng, accuracy?, speed?, heading?, recorded_at?}
 *
 * Guards, in order:
 *  1. session auth + CSRF
 *  2. the feature flag is on
 *  3. the caller is the assigned driver of a live, dispatched fleet trip
 *  4. per-trip rate limit + fix quality
 *
 * Writes only to trip_gps_points. It never changes request/driver/vehicle rows,
 * and it never trusts a request id supplied by the client.
 */

require_once INCLUDES_PATH . '/gps_tracking.php';
requireAuth();
requireCsrf();

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$respond = static function (array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $respond(['ok' => false, 'error' => 'POST required.'], 405);
}

$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > 4096) {
    $respond(['ok' => false, 'error' => 'Payload too large.'], 413);
}
$body = json_decode($raw, true);
if (!is_array($body)) {
    $body = $_POST;
}
foreach ($body as $k => $v) {
    if (is_scalar($v) && !isset($_POST[$k])) {
        $_POST[$k] = $v;
    }
}

if (!gpsTrackingEnabled()) {
    $respond(['ok' => false, 'error' => 'GPS tracking is switched off.', 'disabled' => true], 403);
}

// The trip is derived from the SESSION, never from the payload.
$trip = gpsTrackingCurrentTripForDriver();
if ($trip === null) {
    $respond([
        'ok' => false,
        'error' => 'No trip is currently dispatching with you, so there is nothing to track.',
        'inactive' => true,
    ], 409);
}

$fix = [
    'lat' => $body['lat'] ?? null,
    'lng' => $body['lng'] ?? null,
    'accuracy' => $body['accuracy'] ?? null,
    'speed' => $body['speed'] ?? null,
    'heading' => $body['heading'] ?? null,
    'recorded_at' => $body['recorded_at'] ?? null,
];

$result = gpsRecordPing((int) $trip->id, (int) userId(), $fix);

if (!$result['ok']) {
    // 429 for cadence, 422 for a bad fix — both are expected client-side states.
    $code = $result['reason'] === 'too frequent' ? 429 : 422;
    $respond(['ok' => false, 'skipped' => true, 'reason' => $result['reason']], $code);
}

$respond([
    'ok' => true,
    'request_id' => (int) $trip->id,
    'id' => $result['id'],
    'plate' => $trip->plate_number ?? null,
]);