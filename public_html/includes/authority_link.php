<?php
/**
 * Plan #44 — TO/OB → LOKA vehicle-request authority link.
 *
 * A Travel Order (tostage/prod TO app) that needs an official vehicle calls
 * the LOKA authority-vehicle-link API; this include holds the shared logic:
 * HMAC verification of the server-to-server call, requester matching by SSO
 * email, idempotent draft creation with prefilled fields and passengers.
 *
 * The authority document (TO) owns purpose/dates/destination/travelers; LOKA
 * owns vehicle, driver, approvals, dispatch. Linked requests keep the four
 * authority-owned fields locked on edit.
 */

if (!defined('AUTHORITY_LINK_SECRET')) {
    // Shared HMAC secret with the TO app (staging .env.lokastage / TO .env).
    $authorityLinkSecret = getenv('AUTHORITY_LINK_SECRET');
    define('AUTHORITY_LINK_SECRET', is_string($authorityLinkSecret) ? trim($authorityLinkSecret) : '');
    unset($authorityLinkSecret);
}

/** TO app base URL for deep links back to the authority document. */
if (!defined('TO_APP_URL')) {
    define('TO_APP_URL', rtrim((string) (getenv('TO_APP_URL') ?: 'https://tostage.dictr2.cloud'), '/'));
}

/** LOKA statuses a newly linked request may take. */
const AUTHORITY_LINK_STATUS = STATUS_DRAFT;

/**
 * Constant-time HMAC-SHA256 check of the request signature over the raw body.
 */
function authorityLinkVerifySignature(string $rawBody, ?string $signature): bool
{
    if (AUTHORITY_LINK_SECRET === '' || !is_string($signature) || $signature === '') {
        return false;
    }
    $expected = hash_hmac('sha256', $rawBody, AUTHORITY_LINK_SECRET);
    return hash_equals($expected, strtolower($signature));
}

/**
 * Find the active local LOKA user for an SSO email.
 */
function authorityLinkFindUser(string $email): ?object
{
    $email = trim(strtolower($email));
    if ($email === '') {
        return null;
    }
    return db()->fetch(
        "SELECT * FROM users WHERE email = ? AND status = 'active' AND deleted_at IS NULL LIMIT 1",
        [$email]
    );
}

/**
 * Idempotently create (or return) the LOKA request linked to a Travel Order.
 *
 * Payload (already JSON-decoded, validated by the caller):
 *   to_id, to_code, purpose, start_date (Y-m-d), end_date (Y-m-d),
 *   destination, requester_email, travelers: [{name, email}]
 *
 * @return array{ok:bool,error:string,created:bool,request_id:?int,to_request_id:?int}
 */
function authorityLinkCreateForTravelOrder(array $p): array
{
    $toId = (int) ($p['to_id'] ?? 0);
    if ($toId <= 0) {
        return ['ok' => false, 'error' => 'to_id is required.', 'created' => false, 'request_id' => null, 'to_request_id' => null];
    }

    // Idempotency first: a TO can only ever own one LOKA request.
    $existing = db()->fetch(
        "SELECT id, status, user_id FROM requests WHERE to_request_id = ? AND deleted_at IS NULL LIMIT 1",
        [$toId]
    );
    if ($existing !== null) {
        return ['ok' => true, 'error' => '', 'created' => false,
                'request_id' => (int) $existing->id, 'to_request_id' => $toId];
    }

    $requester = authorityLinkFindUser((string) ($p['requester_email'] ?? ''));
    if ($requester === null) {
        return ['ok' => false,
                'error' => 'No active LOKA account for requester_email.',
                'created' => false, 'request_id' => null, 'to_request_id' => null];
    }

    $start = (string) ($p['start_date'] ?? '');
    $end = (string) ($p['end_date'] ?? '');
    $startDt = DateTime::createFromFormat('Y-m-d', $start);
    $endDt = DateTime::createFromFormat('Y-m-d', $end);
    if ($startDt === false || $endDt === false || $endDt < $startDt) {
        return ['ok' => false, 'error' => 'start_date/end_date must be Y-m-d with end >= start.',
                'created' => false, 'request_id' => null, 'to_request_id' => null];
    }
    // TO carries dates only; LOKA needs datetimes — standard office hours.
    $startSql = $start . ' 08:00:00';
    $endSql = $end . ' 17:00:00';

    $purpose = trim((string) ($p['purpose'] ?? ''));
    $destination = trim((string) ($p['destination'] ?? ''));
    if ($purpose === '' || $destination === '') {
        return ['ok' => false, 'error' => 'purpose and destination are required.',
                'created' => false, 'request_id' => null, 'to_request_id' => null];
    }

    $now = date(DATETIME_FORMAT);
    db()->beginTransaction();
    try {
        $requestId = (int) db()->insert('requests', [
            'user_id' => (int) $requester->id,
            'department_id' => (int) ($requester->department_id ?? 0),
            'start_datetime' => $startSql,
            'end_datetime' => $endSql,
            'purpose' => $purpose,
            'destination' => $destination,
            'passenger_count' => 0,
            'status' => AUTHORITY_LINK_STATUS,
            'notes' => 'Created from Travel Order ' . trim((string) ($p['to_code'] ?? ('#' . $toId)))
                     . ' — purpose, dates, destination and travelers are managed by the Travel Order.',
            'to_request_id' => $toId,
            'to_code' => trim((string) ($p['to_code'] ?? '')) ?: null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Travelers → request_passengers. Match by SSO email; unknown names
        // become guests. The requester themself is never double-counted.
        $travelers = is_array($p['travelers'] ?? null) ? $p['travelers'] : [];
        $requesterEmail = strtolower(trim((string) $requester->email));
        $count = 0;
        $seen = [];
        foreach ($travelers as $t) {
            if (!is_array($t)) {
                continue;
            }
            $tEmail = strtolower(trim((string) ($t['email'] ?? '')));
            $tName = trim((string) ($t['name'] ?? ''));
            if ($tEmail === $requesterEmail) {
                continue; // the requester is the trip owner, not a separate passenger
            }
            $key = $tEmail !== '' ? $tEmail : 'name:' . mb_strtolower($tName);
            if ($key === 'name:' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $passengerUser = $tEmail !== '' ? authorityLinkFindUser($tEmail) : null;
            db()->insert('request_passengers', [
                'request_id' => $requestId,
                'user_id' => $passengerUser->id ?? null,
                'guest_name' => $passengerUser === null ? ($tName ?: ($tEmail ?: null)) : null,
                'created_at' => $now,
            ]);
            $count++;
        }
        db()->update('requests', ['passenger_count' => $count, 'updated_at' => date(DATETIME_FORMAT)],
                     'id = ?', [$requestId]);

        auditLog('authority_link_created', 'request', $requestId, null,
                 ['to_request_id' => $toId, 'to_code' => $p['to_code'] ?? '', 'passengers' => $count,
                  'requester_user_id' => (int) $requester->id]);
        db()->commit();

        return ['ok' => true, 'error' => '', 'created' => true, 'request_id' => $requestId,
                'to_request_id' => $toId];
    } catch (Throwable $e) {
        if (dbInTransaction()) {
            db()->rollback();
        }
        error_log('authorityLinkCreateForTravelOrder: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Failed to create the linked request.',
                'created' => false, 'request_id' => null, 'to_request_id' => null];
    }
}

/**
 * Deep link back to the authority document on the TO app.
 */
function authorityLinkToUrl(int $toId): string
{
    return TO_APP_URL . '/DICT/travel-orders/' . (int) $toId;
}
