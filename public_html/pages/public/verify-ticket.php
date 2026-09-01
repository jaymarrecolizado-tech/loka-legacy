<?php
/**
 * LOKA - Public Trip Ticket Verification
 * Scanned from QR on vehicle summary trip ticket.
 */
$no = (string) get('no', '');
$hash = (string) get('hash', '');
$error = null;
$isValid = false;

if ($no === '' || $hash === '') {
    $error = 'Invalid verification link. Please scan the QR code printed on the official trip ticket.';
} elseif (!tripTicketVerifyHashValid($no, $hash)) {
    $error = 'Authenticity could not be verified. This document may be fraudulent or altered.';
} else {
    $isValid = true;
}
?>
<?php if (!defined('IN_PUBLIC_VERIFY')): ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Trip Ticket | <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>body{background:linear-gradient(160deg,#e8eef5 0%,#d5e0ec 100%);min-height:100vh;}</style>
</head>
<body class="d-flex align-items-center justify-content-center p-3">
<div class="w-100 bg-white rounded-4 shadow overflow-hidden my-4" style="max-width:480px;">
    <div class="bg-primary text-white p-4 text-center" style="background:#0b3d6e !important;">
        <p class="small text-uppercase text-white-50 mb-1" style="letter-spacing:.15em;">DICT Region II</p>
        <h1 class="h5 fw-bold">Trip Ticket Check</h1>
        <p class="small text-white-50 mt-1">Scan result</p>
    </div>
    <div class="p-4">
        <?php if (!$isValid): ?>
            <div class="rounded-3 bg-danger text-white p-3 d-flex gap-3 align-items-start mb-3">
                <i class="bi bi-shield-exclamation fs-3"></i>
                <div>
                    <h2 class="h6 fw-bold mb-1">Not Valid</h2>
                    <p class="small mb-0 text-white-50"><?= e($error) ?></p>
                </div>
            </div>
            <p class="text-center small text-muted">Do not honor this document. Contact DICT Region II Motor Pool.</p>
        <?php else: ?>
            <div class="rounded-3 bg-success text-white p-3 d-flex gap-3 align-items-center mb-4">
                <div class="bg-white bg-opacity-25 rounded-circle p-2"><i class="bi bi-check-lg fs-4"></i></div>
                <div>
                    <h2 class="h5 fw-bold mb-0">AUTHENTIC</h2>
                    <p class="small mb-0 text-white-50">Valid trip ticket</p>
                </div>
            </div>
            <div class="rounded-3 border p-3 d-flex justify-content-between">
                <div>
                    <p class="small text-uppercase text-muted fw-semibold" style="font-size:10px;">Trip No.</p>
                    <p class="h5 fw-bold text-primary mb-0"><?= e($no) ?></p>
                </div>
                <div class="text-end">
                    <p class="small text-uppercase text-muted fw-semibold" style="font-size:10px;">Verified</p>
                    <p class="small fw-semibold mb-0"><?= e(date('M d, Y h:i A')) ?></p>
                </div>
            </div>
            <p class="mt-3 text-center small text-muted">Official verification · DICT Region II · LOKA Fleet</p>
        <?php endif; ?>
        <div class="text-center mt-3">
            <a href="<?= APP_URL ?>/" class="btn btn-outline-primary btn-sm"><i class="bi bi-house me-1"></i>Go to LOKA</a>
        </div>
    </div>
</div>
</body>
</html>
<?php endif; ?>
