<?php
/**
 * LOKA - Public Certificate of Appearance verification (Plan #36)
 *
 * Opened by scanning the QR printed on / shown with an OB Pass Slip CoA.
 * The HMAC hash proves the link is ours. Shows limited fields only, with the
 * representative contact MASKED — spot-check callers get numbers from the
 * Motorpool/admin list, not from the public page.
 */

if (!function_exists('obFind')) {
    require_once INCLUDES_PATH . '/ob_requests.php';
}

$id = (int) get('id', 0);
$hash = (string) get('hash', '');
$ob = null;
$error = null;

if (!$id || $hash === '') {
    $error = 'Invalid verification link. Please scan the QR code printed on the Certificate of Appearance.';
} else {
    $ob = obFind($id);
    if (!$ob) {
        $error = 'Pass Slip not found.';
    } elseif (!obCoaVerifyHashValid($ob, $hash)) {
        $error = 'This Certificate of Appearance could not be verified. It may be fraudulent or altered.';
        $ob = null; // no details on failed signature
    } elseif (!obCoaReceived($ob)) {
        $error = 'The Certificate of Appearance for this pass slip has not been accomplished yet.';
    }
}

$isValid = $ob !== null && $error === null;

/** Mask a mobile: keep the last 4 digits. */
$maskMobile = static function (string $mobile): string {
    $digits = preg_replace('/\D+/', '', $mobile) ?? '';
    return str_repeat('•', max(0, strlen($digits) - 4)) . substr($digits, -4);
};

/** Mask an email: first two chars of the local part + ***@domain. */
$maskEmail = static function (string $email): string {
    $at = strpos($email, '@');
    if ($at === false || $at === 0) {
        return '•••••';
    }
    $local = substr($email, 0, 2);
    return $local . str_repeat('•', max(3, $at - 2)) . substr($email, $at);
};

$statusLabel = $isValid ? obStatusLabel($ob->status) : '';
$ackAt = $isValid ? obCoaAcknowledgedAt($ob) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Certificate of Appearance | <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>body{background:linear-gradient(160deg,#e8eef5 0%,#d5e0ec 100%);min-height:100vh;}</style>
</head>
<body class="d-flex align-items-center justify-content-center p-3">
<div class="w-100 bg-white rounded-4 shadow overflow-hidden my-4" style="max-width:480px;">
    <div class="text-white p-4 text-center" style="background:#0b3d6e;">
        <p class="small text-uppercase text-white-50 mb-1" style="letter-spacing:.15em;">DICT Region II</p>
        <h1 class="h5 fw-bold mb-0">Certificate of Appearance Check</h1>
        <p class="small text-white-50 mt-1 mb-0">Scan result for official business validation</p>
    </div>
    <div class="p-4">

    <?php if (!$isValid): ?>
        <div class="rounded-3 bg-danger text-white p-3 d-flex gap-3 align-items-start mb-3">
            <i class="bi bi-shield-exclamation fs-3"></i>
            <div>
                <h2 class="h6 fw-bold mb-1">Not Verified</h2>
                <p class="small mb-0 text-white-50"><?= e($error ?? 'Unable to verify this certificate.') ?></p>
            </div>
        </div>
        <p class="text-center small text-muted mb-0">
            Contact DICT Region II if you need to validate an Official Business visit.
        </p>
    <?php else: ?>
        <div class="rounded-3 bg-success text-white p-3 d-flex gap-3 align-items-center mb-4">
            <div class="bg-white bg-opacity-25 rounded-circle p-2"><i class="bi bi-check-lg fs-4"></i></div>
            <div>
                <h2 class="h5 fw-bold mb-0">VERIFIED</h2>
                <p class="small mb-0 text-white-50">Acknowledged by the receiving office</p>
            </div>
        </div>

        <div class="rounded-3 border p-3 d-flex justify-content-between mb-3">
            <div>
                <p class="text-uppercase text-muted fw-semibold mb-1" style="font-size:10px;">Pass Slip No.</p>
                <p class="h5 fw-bold text-primary mb-0"><?= e($ob->pass_slip_no) ?></p>
            </div>
            <div class="text-end">
                <p class="text-uppercase text-muted fw-semibold mb-1" style="font-size:10px;">Date of OB</p>
                <p class="fw-semibold mb-0"><?= e(date('M d, Y', strtotime($ob->ob_date))) ?></p>
            </div>
        </div>

        <div class="rounded-3 border border-primary-subtle bg-primary-subtle p-3 mb-3">
            <p class="small fw-bold text-primary mb-2">Visit Details</p>
            <div class="row small g-1">
                <div class="col-4 text-muted">Personnel</div>
                <div class="col-8 fw-bold"><?= e(obJoinNames(obParticipantFullNames((int) $ob->id, (string) $ob->employee_name))) ?></div>
                <div class="col-4 text-muted">Office</div>
                <div class="col-8 fw-bold"><?= e($ob->coa_office ?: '—') ?></div>
                <div class="col-4 text-muted">Representative</div>
                <div class="col-8 fw-bold"><?= e($ob->coa_representative ?: '—') ?></div>
                <div class="col-4 text-muted">Time</div>
                <div class="col-8 fw-bold"><?= e($ob->coa_time_from ?: '—') ?> – <?= e($ob->coa_time_to ?: '—') ?></div>
            </div>
        </div>

        <div class="rounded-3 border p-3 small mb-3">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-muted">Acknowledged</span>
                <span class="fw-semibold text-end"><?= $ackAt ? e(date('M d, Y g:i A', strtotime($ackAt))) : '—' ?></span>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-muted">Slip status</span>
                <span class="badge bg-<?= e(obStatusColor($ob->status)) ?>"><?= e($statusLabel) ?></span>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-0">
                <span class="text-muted">Contact on file</span>
                <span class="fw-semibold text-end">
                    <?= $ob->coa_contact_mobile ? e($maskMobile((string) $ob->coa_contact_mobile)) : '' ?>
                    <?= $ob->coa_contact_mobile && $ob->coa_contact_email ? ' · ' : '' ?>
                    <?= $ob->coa_contact_email ? e($maskEmail((string) $ob->coa_contact_email)) : '' ?>
                    <?= !$ob->coa_contact_mobile && !$ob->coa_contact_email ? '—' : '' ?>
                </span>
            </div>
        </div>

        <p class="mt-3 mb-0 text-center small text-muted">
            Official verification · DICT Region II · LOKA Fleet<br>
            Scanned <?= e(date('M d, Y h:i A')) ?>
        </p>
    <?php endif; ?>

        <div class="text-center mt-3">
            <a href="<?= APP_URL ?>/" class="btn btn-outline-primary btn-sm"><i class="bi bi-house me-1"></i>Go to LOKA</a>
        </div>
    </div>
</div>
</body>
</html>
