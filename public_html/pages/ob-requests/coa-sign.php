<?php
/**
 * LOKA - Certificate of Appearance Signing Kiosk (Plans #22 + #25)
 * The ONE CoA surface: public one-time token link, and the destination of
 * the on-device "Fill Certificate" button. No login; the token is the
 * capability. Expires after submit or after ob_coa_token_days.
 * Rate-limited per session.
 *
 * Route: ?page=ob-requests&action=coa-sign&token=RAW
 * The form posts to the CURRENT URL and also carries a hidden token, so a
 * stripped query string falls back to POST (never to requireAuth/login).
 */

if (!function_exists('obFind')) {
    require_once INCLUDES_PATH . '/ob_requests.php';
}

// Token from the query string, falling back to the posted hidden field
$raw = trim((string) get('token', ''));
if ($raw === '') {
    $raw = trim((string) post('token', ''));
}
$ob = $raw !== '' ? obFindCoaByToken($raw) : null;

$error = '';
$done = false;
$errors = [];

if (!$raw || !$ob) {
    $error = 'This signing link is invalid or has expired.';
} elseif (!in_array($ob->status, ['approved', 'departed'], true)) {
    $error = 'This pass slip is no longer awaiting a Certificate of Appearance.';
} elseif ($ob->coa_signature_path !== null) {
    $done = true; // already signed
}

// Simple per-session rate limit on attempts (token is the capability)
$_SESSION['ob_coa_attempts'] = ($_SESSION['ob_coa_attempts'] ?? 0) + ($_SERVER['REQUEST_METHOD'] === 'POST' ? 1 : 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error && !$done && $ob) {
    if ($_SESSION['ob_coa_attempts'] > 25) {
        $error = 'Too many attempts. Please ask the employee for a fresh link.';
    } else {
        $office = trim((string) post('coa_office', ''));
        $rep = trim((string) post('coa_representative', ''));
        $purpose = trim((string) post('coa_purpose', ''));
        $from = trim((string) post('coa_time_from', ''));
        $to = trim((string) post('coa_time_to', ''));
        $signature = (string) post('coa_signature', '');

        if ($office === '' || mb_strlen($office) > 200) $errors[] = 'Office / Establishment is required (max 200).';
        if ($rep === '' || mb_strlen($rep) > 150) $errors[] = 'Representative name is required (max 150).';
        if (mb_strlen($purpose) > 300) $errors[] = 'Purpose of visit is too long (max 300).';
        if ($from === '' || $to === '') $errors[] = 'From and To times are required.';
        if ($signature === '') $errors[] = 'The representative signature is required.';

        if (empty($errors)) {
            $sigPath = obSaveSignature((int) $ob->id, 'coa', $signature);
            if ($sigPath === null) {
                $errors[] = 'Could not save the signature. Please sign again.';
            } else {
                $now = date(DATETIME_FORMAT);
                db()->update('ob_requests', [
                    'coa_office' => $office,
                    'coa_representative' => $rep,
                    'coa_purpose' => $purpose !== '' ? $purpose : null,
                    'coa_time_from' => substr($from, 0, 20),
                    'coa_time_to' => substr($to, 0, 20),
                    'coa_signature_path' => $sigPath,
                    'coa_signed_at' => $now,
                    'status' => 'coa_received',
                    'updated_at' => $now,
                ], 'id = ?', [$ob->id]);
                // one-time use
                obClearCoaToken((int) $ob->id);
                obLog((int) $ob->id, 'client', 'coa_signed', null, 'Public link — ' . $rep);
                obNotify((int) $ob->user_id, 'ob_coa_signed', 'OB Pass Slip Ready to Finalize',
                    'The Certificate of Appearance for Pass Slip ' . $ob->pass_slip_no . ' was signed by ' . $rep
                    . '. It is ready to finalize.',
                    '/?page=ob-requests&action=view&id=' . $ob->id);
                auditLog('ob_coa_signed', 'ob_request', (int) $ob->id, null, ['mode' => 'public-token']);
                $done = true;
            }
        }
    }
}

// ---- kiosk variables ----
$kioskState = $done ? 'done' : ($error !== '' ? 'dead' : 'form');
$kioskOb = $ob;
$kioskError = $error;
$kioskErrors = $errors;
$kioskToken = $raw;
// current URL keeps the query string alive on POST; the hidden token above
// covers a stripped query string
$kioskAction = $_SERVER['REQUEST_URI'] ?? '';
$kioskDays = obCoaTokenDays();
$kioskAppearance = $ob !== null
    ? e(obCoaAppearanceLine(
        obParticipantFullNames((int) $ob->id, (string) $ob->employee_name),
        date('l, F j, Y', strtotime($ob->ob_date))
    ))
    : '';
// "Return to pass slip" — the employee's view; login only if they were
// already logged out (never forced here)
$kioskReturnUrl = $ob !== null ? APP_URL . '/?page=ob-requests&action=view&id=' . (int) $ob->id : null;

require PAGES_PATH . '/ob-requests/partials/coa_kiosk.php';
