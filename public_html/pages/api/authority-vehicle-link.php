<?php
/**
 * Plan #44 — TO → LOKA vehicle-request link intake (server-to-server).
 *
 * POST ?page=api&action=authority_vehicle_link
 *   Headers: Content-Type: application/json
 *            X-Authority-Signature: hex hmac_sha256(raw body, AUTHORITY_LINK_SECRET)
 *   Body: {to_id, to_code, purpose, start_date, end_date, destination,
 *          requester_email, travelers:[{name,email}]}
 *
 * No session — the HMAC shared secret (both apps' .env) is the credential.
 * Idempotent: the same to_id always maps to the same LOKA request.
 * Writes only requests + request_passengers; the TO stays the authority for
 * purpose/dates/destination/travelers.
 */

require_once INCLUDES_PATH . '/authority_link.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$respond = static function (array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $respond(['ok' => false, 'error' => 'POST required.'], 405);
}

$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > 100_000) {
    $respond(['ok' => false, 'error' => 'Payload too large.'], 413);
}
if (!authorityLinkVerifySignature($raw, $_SERVER['HTTP_X_AUTHORITY_SIGNATURE'] ?? null)) {
    // Same answer for missing/invalid signature — no oracle about which failed.
    $respond(['ok' => false, 'error' => 'Invalid or missing authority signature.'], 401);
}

$p = json_decode($raw, true);
if (!is_array($p)) {
    $respond(['ok' => false, 'error' => 'Body must be a JSON object.'], 400);
}

$result = authorityLinkCreateForTravelOrder($p);
if (!$result['ok']) {
    $respond(['ok' => false, 'error' => $result['error']], 422);
}

$respond([
    'ok' => true,
    'created' => $result['created'],
    'request_id' => $result['request_id'],
    'status' => AUTHORITY_LINK_STATUS,
    'deep_link' => rtrim(APP_URL, '/') . '/?page=requests&action=view&id=' . (int) $result['request_id'],
]);
