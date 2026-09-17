<?php
/**
 * LOKA - Certificate of Appearance, PUBLIC token mode (Plan #22)
 * One-time token link — no login. Token is the capability; expires after
 * submit or after ob_coa_token_days. Rate-limited per session.
 * Route: ?page=ob-requests&action=coa-sign&token=RAW
 */

if (!function_exists('obFind')) {
    require_once INCLUDES_PATH . '/ob_requests.php';
}

$raw = trim((string) get('token', ''));
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Certificate of Appearance — <?= e($ob->pass_slip_no ?? 'Pass Slip') ?> | <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>body{background:linear-gradient(160deg,#e8eef5 0%,#d5e0ec 100%);min-height:100vh;}</style>
</head>
<body class="d-flex align-items-center justify-content-center p-3">
<div class="w-100 bg-white rounded-4 shadow overflow-hidden my-4" style="max-width:640px;">
    <div class="text-white p-4 text-center" style="background:#0b3d6e;">
        <p class="small text-uppercase text-white-50 mb-1" style="letter-spacing:.15em;">DICT Region II</p>
        <h1 class="h5 fw-bold mb-0">Certificate of Appearance</h1>
        <?php if ($ob): ?><p class="small text-white-50 mt-1 mb-0">Pass Slip No. <?= e($ob->pass_slip_no) ?></p><?php endif; ?>
    </div>
    <div class="p-4">

        <?php if ($error && !$done): ?>
            <div class="rounded-3 bg-danger text-white p-3 d-flex gap-3 align-items-start mb-3">
                <i class="bi bi-shield-exclamation fs-3"></i>
                <div>
                    <h2 class="h6 fw-bold mb-1">Link Not Usable</h2>
                    <p class="small mb-0 text-white-50"><?= e($error) ?></p>
                </div>
            </div>
            <?php foreach ($errors as $er): ?><p class="small text-danger mb-0"><?= e($er) ?></p><?php endforeach; ?>
        <?php elseif ($done): ?>
            <div class="rounded-3 bg-success text-white p-3 d-flex gap-3 align-items-center mb-3">
                <div class="bg-white bg-opacity-25 rounded-circle p-2"><i class="bi bi-check-lg fs-4"></i></div>
                <div>
                    <h2 class="h5 fw-bold mb-0">Thank you!</h2>
                    <p class="small mb-0 text-white-50">The Certificate of Appearance has been recorded.</p>
                </div>
            </div>
            <p class="small text-muted mb-0">The requester has been notified and will finalize the pass slip. You may close this page.</p>
        <?php else: ?>
            <div class="alert alert-info py-2 px-3 small">
                <i class="bi bi-shield-check me-1"></i>
                <?= e(obCoaAppearanceLine(
                    obParticipantFullNames((int) $ob->id, (string) $ob->employee_name),
                    date('l, F j, Y', strtotime($ob->ob_date))
                )) ?>
            </div>

            <?php foreach ($errors as $er): ?>
                <div class="alert alert-danger py-2 px-3 small"><?= e($er) ?></div>
            <?php endforeach; ?>

            <form method="POST" id="coaForm">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label">Office / Establishment <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="coa_office" maxlength="200" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Representative Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="coa_representative" maxlength="150" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Purpose of visit <span class="text-muted">(optional)</span></label>
                        <input type="text" class="form-control" name="coa_purpose" maxlength="300" value="<?= e($ob->purpose) ?>">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">From <span class="text-danger">*</span></label>
                        <input type="time" class="form-control" name="coa_time_from" required>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">To <span class="text-danger">*</span></label>
                        <input type="time" class="form-control" name="coa_time_to" required>
                    </div>
                </div>

                <hr class="my-4">
                <label class="form-label fw-semibold">Representative Signature <span class="text-danger">*</span></label>
                <div class="border rounded bg-white position-relative" id="obSigPad" style="touch-action:none;">
                    <canvas id="obSigCanvas" class="w-100 d-block" style="height:160px; cursor:crosshair;"></canvas>
                    <span class="position-absolute text-muted small" style="top:6px; left:10px; pointer-events:none;">Sign here</span>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="obSigClear"><i class="bi bi-eraser me-1"></i>Clear</button>

                <hr class="my-4">
                <button type="submit" class="btn btn-primary w-100 btn-lg"><i class="bi bi-check-lg me-1"></i>Sign &amp; Submit</button>
                <p class="text-center text-muted small mt-3 mb-0"><i class="bi bi-lock me-1"></i>One-time link · expires after use or in <?= (int) obCoaTokenDays() ?> day(s)</p>
            </form>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= ASSETS_PATH ?>/js/ob-signature.js?v=<?= e(APP_VERSION) ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    ObSignature.init({ canvas: '#obSigCanvas', pad: '#obSigPad', clear: '#obSigClear' });
    var form = document.getElementById('coaForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            if (ObSignature.isEmpty()) {
                e.preventDefault();
                alert('Please sign before submitting.');
            } else {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'coa_signature';
                input.value = ObSignature.toDataUrl();
                this.appendChild(input);
            }
        });
    }
});
</script>
</body>
</html>
