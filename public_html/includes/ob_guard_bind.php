<?php
/**
 * Plan #24 — official vs private vehicle + Guard stamp bound to fleet dispatch.
 * Loaded from ob_requests.php after the Plan #22/#23 helpers exist.
 */

if (defined('OB_GUARD_BIND_LOADED')) {
    return;
}
define('OB_GUARD_BIND_LOADED', 1);

/** Official DICT vehicle (Motorpool) vs private car (skip Motorpool). Pre-051 slips default official. */
function obUsesOfficialVehicle(object $ob): bool
{
    return (int) ($ob->uses_official_vehicle ?? 1) === 1;
}

/** Non-cancelled vehicle request bound to an OB slip, if any. */
function obBoundRequestForOb(int $obId): ?object
{
    return db()->fetch(
        "SELECT r.* FROM requests r
         WHERE r.ob_request_id = ? AND r.deleted_at IS NULL AND r.status <> 'cancelled'
         ORDER BY r.id DESC LIMIT 1",
        [$obId]
    );
}

/** OB bound to a vehicle request — Guard dispatch stamps this slip at the gate. */
function obBoundObForRequest(int $requestId): ?object
{
    return db()->fetch(
        "SELECT o.* FROM ob_requests o
         JOIN requests r ON r.ob_request_id = o.id
         WHERE r.id = ? AND r.deleted_at IS NULL AND r.status <> 'cancelled'
           AND o.deleted_at IS NULL
         LIMIT 1",
        [$requestId]
    );
}

/**
 * request_id => {request_id, id, pass_slip_no} for Guard dispatch modals.
 *
 * @return array<int,object>
 */
function obBoundPassSlipsByRequestId(): array
{
    $out = [];
    foreach (db()->fetchAll(
        "SELECT r.id AS request_id, o.id, o.pass_slip_no
         FROM requests r
         JOIN ob_requests o ON o.id = r.ob_request_id
         WHERE r.deleted_at IS NULL AND r.status <> 'cancelled' AND o.deleted_at IS NULL"
    ) as $row) {
        $out[(int) $row->request_id] = $row;
    }
    return $out;
}

/**
 * Copy recorded fleet dispatch/arrival onto a newly bound slip.
 * Signature is copied only when that Guard has a saved e-sign — never invented.
 *
 * @return list<string> sides copied ('departure', 'arrival')
 */
function obCopyTripTimesFromRequest(int $obId, int $requestId, ?int $actorId = null): array
{
    $ob = obFind($obId);
    $trip = db()->fetch(
        "SELECT actual_dispatch_datetime, actual_arrival_datetime, dispatch_guard_id, arrival_guard_id
         FROM requests WHERE id = ? AND deleted_at IS NULL",
        [$requestId]
    );
    if (!$ob || !$trip) {
        return [];
    }

    $copied = [];
    if ($trip->actual_dispatch_datetime) {
        $stamp = obStampGuardDeparture(
            $ob,
            (string) $trip->actual_dispatch_datetime,
            (int) ($trip->dispatch_guard_id ?: ($actorId ?? 0)),
            '',
            false,
            false
        );
        if ($stamp['ok'] && !$stamp['skipped']) {
            $copied[] = 'departure';
        }
        $ob = $stamp['ob'] ?? obFind($obId) ?? $ob;
    }
    if ($trip->actual_arrival_datetime) {
        $stamp = obStampGuardArrival(
            $ob,
            (string) $trip->actual_arrival_datetime,
            (int) ($trip->arrival_guard_id ?: ($actorId ?? 0))
        );
        if ($stamp['ok'] && !$stamp['skipped']) {
            $copied[] = 'arrival';
        }
    }
    if ($copied !== []) {
        obLog($obId, 'system', 'late_bind_copied_trip_times', $actorId, implode(', ', $copied));
    }
    return $copied;
}

/**
 * Stamp OB departure with a caller-supplied datetime (vehicle dispatch).
 * Already-stamped slips are skipped. $requireSignature=false = late-bind time-only.
 *
 * @return array{ok:bool,skipped:bool,error:?string,ob:?object}
 */
function obStampGuardDeparture(object $ob, string $datetime, int $guardId, string $canvasSig = '', bool $saveEsign = false, bool $requireSignature = true): array
{
    $out = ['ok' => false, 'skipped' => false, 'error' => null, 'ob' => null];

    if (!empty($ob->ob_departure_datetime)) {
        $out['ok'] = true;
        $out['skipped'] = true;
        $out['ob'] = $ob;
        return $out;
    }
    if (!in_array($ob->status, ['approved', 'coa_received', 'completed'], true)) {
        $out['error'] = 'Pass Slip ' . $ob->pass_slip_no . ' is not approved yet ('
            . obStatusLabel($ob->status) . ').';
        return $out;
    }

    $res = obResolveStaffSignature((int) $ob->id, 'guard_departure', $guardId, $canvasSig, $saveEsign);
    $sigPath = $res['path'];
    if ($res['error'] !== null) {
        if ($requireSignature) {
            $out['error'] = $res['error'] . ' (Pass Slip ' . $ob->pass_slip_no . ')';
            return $out;
        }
        $sigPath = null;
    }

    return obApplyGuardDepartureStamp($ob, $datetime, $guardId, $sigPath);
}

/**
 * Apply a pre-resolved departure stamp. Resolve the signature BEFORE vehicle
 * dispatch so a missing signature can block the dispatch itself.
 *
 * @return array{ok:bool,skipped:bool,error:?string,ob:?object}
 */
function obApplyGuardDepartureStamp(object $ob, string $datetime, int $guardId, ?string $sigPath): array
{
    $out = ['ok' => false, 'skipped' => false, 'error' => null, 'ob' => null];

    $fresh = obFind((int) $ob->id) ?: $ob;
    if (!empty($fresh->ob_departure_datetime)) {
        $out['ok'] = true;
        $out['skipped'] = true;
        $out['ob'] = $fresh;
        return $out;
    }
    if (!in_array($fresh->status, ['approved', 'coa_received', 'completed'], true)) {
        $out['error'] = 'Pass Slip ' . $fresh->pass_slip_no . ' is not approved yet ('
            . obStatusLabel($fresh->status) . ').';
        return $out;
    }

    $status = $fresh->status === 'approved' ? 'departed' : $fresh->status;
    db()->update('ob_requests', [
        'status' => $status,
        'ob_departure_datetime' => $datetime,
        'departure_guard_id' => $guardId,
        'guard_departure_signature_path' => $sigPath,
        'updated_at' => date(DATETIME_FORMAT),
    ], 'id = ?', [$fresh->id]);
    obLog((int) $fresh->id, 'guard', 'departed', $guardId, null);
    obNotify((int) $fresh->user_id, 'ob_departed', 'OB Departure Recorded',
        'Departure for Pass Slip ' . $fresh->pass_slip_no . ' was recorded at '
        . date('g:i A', strtotime($datetime)) . '.',
        '/?page=ob-requests&action=view&id=' . $fresh->id);

    $out['ok'] = true;
    $out['ob'] = obFind((int) $fresh->id);
    return $out;
}

/**
 * Stamp OB arrival — time only. Already-arrived slips are skipped.
 *
 * @return array{ok:bool,skipped:bool,error:?string,ob:?object}
 */
function obStampGuardArrival(object $ob, string $datetime, int $guardId): array
{
    $out = ['ok' => false, 'skipped' => false, 'error' => null, 'ob' => null];

    if (!empty($ob->ob_arrival_datetime)) {
        $out['ok'] = true;
        $out['skipped'] = true;
        $out['ob'] = $ob;
        return $out;
    }
    if (empty($ob->ob_departure_datetime)) {
        $out['error'] = 'Pass Slip ' . $ob->pass_slip_no . ' has no departure time yet.';
        return $out;
    }
    if (!in_array($ob->status, ['departed', 'coa_received', 'completed'], true)) {
        $out['error'] = 'Pass Slip ' . $ob->pass_slip_no . ' is not out on business ('
            . obStatusLabel($ob->status) . ').';
        return $out;
    }

    db()->update('ob_requests', [
        'ob_arrival_datetime' => $datetime,
        'arrival_guard_id' => $guardId,
        'updated_at' => date(DATETIME_FORMAT),
    ], 'id = ?', [$ob->id]);
    obLog((int) $ob->id, 'guard', 'arrived', $guardId, null);
    obNotify((int) $ob->user_id, 'ob_arrived', 'OB Arrival Recorded',
        'Arrival for Pass Slip ' . $ob->pass_slip_no . ' was recorded at '
        . date('g:i A', strtotime($datetime)) . '.',
        '/?page=ob-requests&action=view&id=' . $ob->id);

    $out['ok'] = true;
    $out['ob'] = obFind((int) $ob->id);
    return $out;
}
