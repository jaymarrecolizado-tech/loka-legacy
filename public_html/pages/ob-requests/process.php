<?php
/**
 * LOKA - OB Pass Slip actions (Plans #22 + #36)
 * Single POST handler. Plan #36 revision: supervisor/motorpool approvals are
 * buttons (logged-in identity + audit log — no canvases); Reject is retired
 * (Revise + terminal Cancel replace it); guard depart/arrive record identity +
 * time only; finalize is gated on the client CoA acknowledgment.
 * Route: POST ?page=ob-requests&action=process
 */

if (!function_exists('obFind')) {
    require_once INCLUDES_PATH . '/ob_requests.php';
}
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectWith('/?page=ob-requests', 'warning', 'Invalid request.');
}
requireCsrf();

$obId = (int) post('ob_id', 0);
$action = (string) post('action', '');
$comments = trim((string) post('comments', ''));

$ob = obFind($obId);
if (!$ob) {
    redirectWith('/?page=ob-requests', 'danger', 'OB Pass Slip not found.');
}
$link = '/?page=ob-requests&action=view&id=' . $ob->id;

/** Convenience: redirect back with a message after logging + auditing. */
$finish = function (string $type, string $flash, array $audit = []) use ($ob, $link, $action) {
    auditLog('ob_' . $action, 'ob_request', (int) $ob->id, null, $audit ?: ['status' => $ob->status]);
    redirectWith($link, $type, $flash);
};

$now = date(DATETIME_FORMAT);

switch ($action) {

    // ------------------------------------------------------------------
    case 'approve_supervisor':
    {
        if ((int) $ob->supervisor_user_id !== (int) userId() || $ob->status !== 'pending_supervisor') {
            redirectWith($link, 'danger', 'You cannot approve this slip right now.');
        }
        // Private vehicles skip Motorpool entirely: supervisor approval completes the flow
        $official = obUsesOfficialVehicle($ob);
        db()->update('ob_requests', [
            'status' => $official ? 'pending_motorpool' : 'approved',
            'updated_at' => $now,
        ], 'id = ?', [$ob->id]);
        obLog($ob->id, 'supervisor', 'approved', userId(), $comments ?: null);
        if ($official) {
            obNotify((int) $ob->user_id, 'ob_supervisor_approved', 'OB Pass Slip Approved by Supervisor',
                'Pass Slip ' . $ob->pass_slip_no . ' was approved by your immediate supervisor and is now awaiting motorpool approval.', $link);
            $finish('success', 'Slip approved — now awaiting motorpool.');
        }
        obNotify((int) $ob->user_id, 'ob_fully_approved', 'OB Pass Slip Approved',
            'Pass Slip ' . $ob->pass_slip_no . ' is fully approved. Print it and have the guard on duty record your departure.', $link);

        // Guard bell (Plan #27): unbound private slip is now stamp-ready at the gate
        if (obBoundRequestForOb((int) $ob->id) === null) {
            notifyRoleUsers([ROLE_GUARD], 'guard_ob_ready', 'OB Pass Slip Ready for the Gate',
                'Pass Slip ' . $ob->pass_slip_no . ' (private vehicle) is approved and waiting for its departure stamp.',
                '/?page=ob-requests');
        }
        $finish('success', 'Slip approved — printable (private vehicle, no Motorpool step).');
    }

    // ------------------------------------------------------------------
    case 'approve_motorpool':
    {
        if (!obUsesOfficialVehicle($ob)) {
            redirectWith($link, 'danger', 'Private-vehicle slips do not go through Motorpool.');
        }
        if (!isMotorpool() || $ob->status !== 'pending_motorpool') {
            redirectWith($link, 'danger', 'You cannot approve this slip right now.');
        }
        db()->update('ob_requests', [
            'status' => 'approved',
            'updated_at' => $now,
        ], 'id = ?', [$ob->id]);
        obLog($ob->id, 'motorpool', 'approved', userId(), $comments ?: null);
        obNotify((int) $ob->user_id, 'ob_fully_approved', 'OB Pass Slip Approved',
            'Pass Slip ' . $ob->pass_slip_no . ' is fully approved. Print it and have the guard on duty record your departure.', $link);

        // Guard bell (Plan #27): unbound official slip is now stamp-ready at the gate
        if (obBoundRequestForOb((int) $ob->id) === null) {
            notifyRoleUsers([ROLE_GUARD], 'guard_ob_ready', 'OB Pass Slip Ready for the Gate',
                'Pass Slip ' . $ob->pass_slip_no . ' is approved and waiting for its departure stamp.',
                '/?page=ob-requests');
        }
        $finish('success', 'Slip fully approved — printable.');
    }

    // ------------------------------------------------------------------
    case 'revision':
    {
        // Either approver may return the slip for revision (Reject is retired — #36)
        $isSupervisor = (int) $ob->supervisor_user_id === (int) userId() && $ob->status === 'pending_supervisor';
        $isMotorpoolStage = isMotorpool() && $ob->status === 'pending_motorpool';
        if (!$isSupervisor && !$isMotorpoolStage) {
            redirectWith($link, 'danger', 'You cannot act on this slip right now.');
        }
        if ($comments === '') {
            redirectWith($link, 'danger', 'Comments are required when returning a slip for revision.');
        }
        db()->update('ob_requests', ['status' => 'revision', 'updated_at' => $now], 'id = ?', [$ob->id]);
        obLog($ob->id, $isSupervisor ? 'supervisor' : 'motorpool', 'revision', userId(), $comments);
        obNotify((int) $ob->user_id, 'ob_revision',
            'OB Pass Slip Sent Back for Revision',
            'Pass Slip ' . $ob->pass_slip_no . ': ' . $comments, $link);
        $finish('info', 'Slip returned for revision.');
    }

    // ------------------------------------------------------------------
    case 'resubmit':
    {
        if ((int) $ob->user_id !== (int) userId() || $ob->status !== 'revision') {
            redirectWith($link, 'danger', 'Only the requester can resubmit a slip returned for revision.');
        }
        db()->update('ob_requests', ['status' => 'pending_supervisor', 'updated_at' => $now], 'id = ?', [$ob->id]);
        obLog($ob->id, 'requester', 'resubmitted', userId(), $comments ?: null);
        obNotify((int) $ob->supervisor_user_id, 'ob_submitted', 'OB Pass Slip For Your Approval',
            'Pass Slip ' . $ob->pass_slip_no . ' was revised and resubmitted for your approval.', $link);
        $finish('success', 'Slip resubmitted to your supervisor.');
    }

    // ------------------------------------------------------------------
    case 'cancel':
    {
        // Terminal cancel: the requester, or the approver currently holding
        // the slip (Plan #36 decision 1 — comments required, requester told).
        $isOwner = (int) $ob->user_id === (int) userId();
        $isSupStage = (int) $ob->supervisor_user_id === (int) userId() && $ob->status === 'pending_supervisor';
        $isMpStage = isMotorpool() && $ob->status === 'pending_motorpool';
        $ownerCancellable = $isOwner && in_array($ob->status, ['pending_supervisor', 'pending_motorpool', 'approved', 'revision'], true);
        if (!$ownerCancellable && !$isSupStage && !$isMpStage) {
            redirectWith($link, 'danger', 'You cannot cancel this slip right now.');
        }
        if (!$isOwner && $comments === '') {
            redirectWith($link, 'danger', 'Comments are required when cancelling a slip.');
        }

        db()->update('ob_requests', ['status' => 'cancelled', 'updated_at' => $now], 'id = ?', [$ob->id]);
        obLog($ob->id, $isOwner ? 'requester' : ($isSupStage ? 'supervisor' : 'motorpool'), 'cancelled', userId(), $comments ?: null);

        if ($isOwner) {
            obNotify((int) $ob->supervisor_user_id, 'ob_cancelled', 'OB Pass Slip Cancelled',
                'Pass Slip ' . $ob->pass_slip_no . ' was cancelled by the requester.', $link);
            if ($ob->motorpool_head_id) {
                obNotify((int) $ob->motorpool_head_id, 'ob_cancelled', 'OB Pass Slip Cancelled',
                    'Pass Slip ' . $ob->pass_slip_no . ' was cancelled by the requester.', $link);
            }
            $finish('warning', 'Slip cancelled.');
        }

        obNotify((int) $ob->user_id, 'ob_cancelled', 'OB Pass Slip Cancelled',
            'Pass Slip ' . $ob->pass_slip_no . ' was cancelled by ' . ($isSupStage ? 'your immediate supervisor' : 'the Motorpool Head')
            . ($comments !== '' ? '. Reason: ' . $comments : '.'), $link);
        $finish('warning', 'Slip cancelled — the requester has been notified.');
    }

    // ------------------------------------------------------------------
    case 'guard_departure':
    {
        if (!canAccessGuardDashboard()) {
            redirectWith($link, 'danger', 'Only guards can record departures.');
        }
        $stamp = obStampGuardDeparture($ob, $now, (int) userId());
        if (!$stamp['ok']) {
            redirectWith($link, 'danger', $stamp['error'] ?? 'Departure could not be recorded.');
        }
        $finish('success', $stamp['skipped'] ? 'Departure was already recorded — nothing changed.' : 'Departure recorded.');
    }

    // ------------------------------------------------------------------
    case 'guard_arrival':
    {
        if (!canAccessGuardDashboard()) {
            redirectWith($link, 'danger', 'Only guards can record arrivals.');
        }
        $stamp = obStampGuardArrival($ob, $now, (int) userId());
        if (!$stamp['ok']) {
            redirectWith($link, 'danger', $stamp['error'] ?? 'Arrival could not be recorded.');
        }
        $finish('success', $stamp['skipped'] ? 'Arrival was already recorded — nothing changed.' : 'Arrival recorded.');
    }

    // ------------------------------------------------------------------
    case 'coa_token':
    {
        if ((int) $ob->user_id !== (int) userId() || !in_array($ob->status, ['approved', 'departed'], true)) {
            redirectWith($link, 'danger', 'The client link can only be issued on your approved slip.');
        }
        $raw = obCreateCoaToken((int) $ob->id);
        auditLog('ob_coa_token_issued', 'ob_request', (int) $ob->id, null, null);
        redirectWith($link . '&token=' . urlencode($raw), 'success', 'Client link generated below.');
    }

    // ------------------------------------------------------------------
    case 'finalize':
    {
        if ((int) $ob->user_id !== (int) userId() || !in_array($ob->status, ['approved', 'departed', 'coa_received'], true)) {
            redirectWith($link, 'danger', 'Only the requester can finalize, after approval.');
        }
        // Plan #36: the client acknowledgment (+ contact, enforced at the
        // kiosk) is the gate; legacy signed slips keep finalizing.
        if (!obCoaReceived($ob)) {
            redirectWith($link, 'danger', 'The Certificate of Appearance acknowledgment is required before finalizing.');
        }
        if (empty($ob->ob_departure_datetime) || empty($ob->ob_arrival_datetime)) {
            // warn-but-allow is surfaced in the UI; log it for the record
            obLog($ob->id, 'requester', 'finalized_missing_guard_times', userId(), null);
        }
        db()->update('ob_requests', [
            'status' => 'completed',
            'finalized_at' => $now,
            'updated_at' => $now,
        ], 'id = ?', [$ob->id]);
        obLog($ob->id, 'requester', 'finalized', userId(), null);
        obNotify((int) $ob->user_id, 'ob_finalized', 'OB Pass Slip Completed',
            'Pass Slip ' . $ob->pass_slip_no . ' has been finalized. The PDF is now the complete record.', $link);
        obNotify((int) $ob->supervisor_user_id, 'ob_finalized', 'OB Pass Slip Completed',
            'Pass Slip ' . $ob->pass_slip_no . ' was finalized by the requester.', $link);
        if ($ob->motorpool_head_id) {
            obNotify((int) $ob->motorpool_head_id, 'ob_finalized', 'OB Pass Slip Completed',
                'Pass Slip ' . $ob->pass_slip_no . ' was finalized by the requester.', $link);
        }
        $finish('success', 'OB Pass Slip finalized.');
    }

    // ------------------------------------------------------------------
    case 'vehicle_bind':
    {
        // All Father toggle: bind an approved OB to an existing vehicle request
        $requestId = (int) post('request_id', 0);
        if (!obAttachAfterSubmitAllowed()) {
            redirectWith($link, 'danger', 'Attaching an OB after submission is disabled (All Father setting).');
        }
        $request = db()->fetch(
            "SELECT id, user_id, status, start_datetime, ob_request_id FROM requests WHERE id = ? AND deleted_at IS NULL",
            [$requestId]
        );
        $allowed = $request && ((int) $request->user_id === (int) userId() || isAdmin());
        if (!$allowed || $request->ob_request_id || in_array($request->status, ['completed', 'cancelled'], true)) {
            redirectWith($link, 'danger', 'That vehicle request cannot be bound.');
        }
        $err = obValidateBind($ob->id, (int) $request->user_id, (string) $request->start_datetime, (int) $request->id);
        if ($err !== null) {
            redirectWith($link, 'danger', $err);
        }
        db()->update('requests', ['ob_request_id' => $ob->id, 'updated_at' => $now], 'id = ?', [$requestId]);
        obLog($ob->id, 'system', 'bound_to_request#' . $requestId, userId(), null);
        obCopyTripTimesFromRequest((int) $ob->id, $requestId, userId());

        auditLog('ob_bound_to_request', 'ob_request', (int) $ob->id, null, ['request_id' => $requestId]);
        redirectWith('/?page=requests&action=view&id=' . $requestId, 'success', 'OB Pass Slip ' . $ob->pass_slip_no . ' attached to the request.');
    }

    default:
        redirectWith($link, 'warning', 'Unknown action.');
}
