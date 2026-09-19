<?php
/**
 * LOKA - Certificate of Appearance entry (Plans #22 + #25)
 * The requester opens their approved OB and taps "Fill Certificate of
 * Appearance (on-device)". This page confirms owner + status, mints a fresh
 * one-time CoA token and REDIRECTS to the public signing kiosk — the client
 * never sees the logged-in sidebar, and there is no second POST handler.
 * Route: ?page=ob-requests&action=coa&id=
 */

if (!function_exists('obFind')) {
    require_once INCLUDES_PATH . '/ob_requests.php';
}
requireAuth();

$ob = obFind((int) get('id', 0));
if (!$ob || (int) $ob->user_id !== (int) userId()) {
    redirectWith('/?page=ob-requests', 'danger', 'OB Pass Slip not found.');
}
if (!in_array($ob->status, ['approved', 'departed'], true)) {
    redirectWith('/?page=ob-requests&action=view&id=' . $ob->id, 'warning',
        'The Certificate of Appearance can only be filled after the slip is approved.');
}

// Mint a fresh one-time token (a previous raw token can never be recovered
// from its hash). Expires after submit or ob_coa_token_days.
$raw = obCreateCoaToken((int) $ob->id);
auditLog('ob_coa_token_issued', 'ob_request', (int) $ob->id, null, ['mode' => 'on-device']);

// Hand the device to the client — the kiosk takes it from here.
redirect('/?page=ob-requests&action=coa-sign&token=' . urlencode($raw));
