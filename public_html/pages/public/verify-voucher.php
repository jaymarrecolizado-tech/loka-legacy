<?php
/**
 * LOKA - Public Gas Voucher Validation Page (no login)
 *
 * Opened when a gasoline station scans the QR code printed on a voucher.
 * The HMAC hash proves the link is ours; the page must tell the station
 * whether the voucher is APPROVED and whether fuel was ALREADY paid.
 * Mobile-first Bootstrap 5 card (no Tailwind), UTF-8.
 */

$id = (int) get('id', 0);
$hash = (string) get('hash', '');
$voucher = null;
$error = null;

if (!$id || $hash === '') {
    $error = 'Invalid verification link. Please scan the QR code printed on the official gas voucher.';
} else {
    $voucher = db()->fetch(
        "SELECT gv.*,
                u.name AS requester_name,
                reviewer.name AS reviewer_name,
                approver.name AS approver_name_full
         FROM gas_vouchers gv
         JOIN users u ON gv.requested_by_user_id = u.id
         LEFT JOIN users reviewer ON gv.reviewed_by = reviewer.id
         LEFT JOIN users approver ON gv.approved_by = approver.id
         WHERE gv.id = ? AND gv.deleted_at IS NULL",
        [$id]
    );

    if (!$voucher) {
        $error = 'Voucher not found or has been deleted.';
    } elseif (!gasVoucherVerifyHashValid($voucher, $hash)) {
        $error = 'Voucher authenticity could not be verified. This document may be fraudulent or altered.';
        $voucher = null; // Do not expose details on failed signature
    } elseif ($voucher->status !== 'approved') {
        $error = 'This voucher is currently "' . gasVoucherStatusLabel($voucher->status)
            . '" and is not valid for fuel release.';
    }
}

$isValid = $voucher !== null && $error === null;
$qtyDisplay = $voucher
    ? (($voucher->unit === 'FULL TANK' || (float) $voucher->quantity <= 0)
        ? 'FULL TANK'
        : (rtrim(rtrim(number_format((float) $voucher->quantity, 2, '.', ''), '0'), '.') . ' ' . $voucher->unit))
    : '';

// Payment status: unpaid is the normal releasable state; paid/processed mean
// the fuel was already released/claimed once — the station must not release
// again. Cancelled payment also blocks a second release.
$paymentStatus = $isValid ? (string) $voucher->payment_status : '';
$paymentNotice = match ($paymentStatus) {
    'paid' => [
        'level' => 'warning',
        'box' => 'bg-warning-subtle border-warning text-warning-emphasis',
        'icon' => 'bi-exclamation-triangle-fill',
        'title' => 'Already Paid',
        'text' => 'This voucher was already marked PAID. Do not release fuel again.',
    ],
    'processed' => [
        'level' => 'warning',
        'box' => 'bg-warning-subtle border-warning text-warning-emphasis',
        'icon' => 'bi-exclamation-triangle-fill',
        'title' => 'Already Processed',
        'text' => 'This voucher was already marked PROCESSED. Do not release fuel again.',
    ],
    'cancelled' => [
        'level' => 'danger',
        'box' => 'bg-danger-subtle border-danger text-danger-emphasis',
        'icon' => 'bi-x-octagon-fill',
        'title' => 'Payment Cancelled',
        'text' => 'Payment for this voucher was CANCELLED. Do not release fuel.',
    ],
    'unpaid' => [
        'level' => 'success',
        'box' => 'bg-success-subtle border-success text-success-emphasis',
        'icon' => 'bi-check-circle',
        'title' => 'Unpaid',
        'text' => 'Fuel not yet released under this voucher.',
    ],
    default => null,
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Gas Voucher | <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>body{background:linear-gradient(160deg,#e8eef5 0%,#d5e0ec 100%);min-height:100vh;}</style>
</head>
<body class="d-flex align-items-center justify-content-center p-3">
<div class="w-100 bg-white rounded-4 shadow overflow-hidden my-4" style="max-width:480px;">
    <div class="text-white p-4 text-center" style="background:#0b3d6e;">
        <p class="small text-uppercase text-white-50 mb-1" style="letter-spacing:.15em;">DICT Region II</p>
        <h1 class="h5 fw-bold mb-0">Gas Voucher Check</h1>
        <p class="small text-white-50 mt-1 mb-0">Scan result for gasoline station</p>
    </div>
    <div class="p-4">

    <?php if (!$isValid): ?>
        <div class="rounded-3 bg-danger text-white p-3 d-flex gap-3 align-items-start mb-3">
            <i class="bi bi-shield-exclamation fs-3"></i>
            <div>
                <h2 class="h6 fw-bold mb-1">Not Valid</h2>
                <p class="small mb-0 text-white-50"><?= e($error ?? 'Unable to verify this voucher.') ?></p>
            </div>
        </div>
        <p class="text-center small text-muted mb-0">
            Do not release fuel for this document. Contact DICT Region II Motor Pool if needed.
        </p>
    <?php else: ?>
        <div class="rounded-3 bg-success text-white p-3 d-flex gap-3 align-items-center mb-4">
            <div class="bg-white bg-opacity-25 rounded-circle p-2"><i class="bi bi-check-lg fs-4"></i></div>
            <div>
                <h2 class="h5 fw-bold mb-0">AUTHENTIC</h2>
                <p class="small mb-0 text-white-50">Approved for fuel / item release</p>
            </div>
        </div>

        <?php if ($paymentNotice !== null && $paymentNotice['level'] !== 'success'): ?>
        <div class="rounded-3 border <?= e($paymentNotice['box']) ?> p-3 d-flex gap-3 align-items-start mb-4">
            <i class="bi <?= e($paymentNotice['icon']) ?> fs-4"></i>
            <div>
                <h2 class="h6 fw-bold mb-1"><?= e($paymentNotice['title']) ?></h2>
                <p class="small mb-0"><?= e($paymentNotice['text']) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <div class="rounded-3 border p-3 d-flex justify-content-between mb-3">
            <div>
                <p class="text-uppercase text-muted fw-semibold mb-1" style="font-size:10px;">Voucher No.</p>
                <p class="h5 fw-bold text-danger mb-0"><?= e($voucher->voucher_no) ?></p>
            </div>
            <div class="text-end">
                <p class="text-uppercase text-muted fw-semibold mb-1" style="font-size:10px;">Date</p>
                <p class="fw-semibold mb-0"><?= e(date('M d, Y', strtotime($voucher->request_date))) ?></p>
            </div>
        </div>

        <?php if (!empty($voucher->gas_station)): ?>
        <div class="rounded-3 border border-warning-subtle bg-warning-subtle p-3 mb-3">
            <p class="text-uppercase text-muted fw-semibold mb-1" style="font-size:10px;">Addressed To</p>
            <p class="fw-bold mb-0"><?= e($voucher->gas_station) ?></p>
        </div>
        <?php endif; ?>

        <div class="rounded-3 border border-primary-subtle bg-primary-subtle p-3 mb-3">
            <p class="small fw-bold text-primary mb-2">Bearer &amp; Vehicle</p>
            <div class="row small g-1">
                <div class="col-4 text-muted">Driver</div>
                <div class="col-8 fw-bold text-uppercase"><?= e($voucher->driver_name) ?></div>
                <div class="col-4 text-muted">Plate No.</div>
                <div class="col-8 fw-bold text-uppercase"><?= e($voucher->vehicle_plate) ?></div>
            </div>
        </div>

        <div class="rounded-3 border border-warning-subtle bg-warning-subtle p-3 mb-3">
            <p class="small fw-bold mb-2" style="color:#8a6d00;">Authorized Items</p>
            <div class="d-flex justify-content-between align-items-end">
                <div>
                    <p class="text-uppercase text-muted mb-1" style="font-size:10px;">Fuel / Article</p>
                    <p class="h5 fw-bold mb-0 text-uppercase"><?= e($voucher->fuel_type) ?></p>
                    <?php if (!empty($voucher->other_items)): ?>
                    <p class="small text-muted mb-0 mt-1">+ <?= e($voucher->other_items) ?></p>
                    <?php endif; ?>
                </div>
                <div class="text-end">
                    <p class="text-uppercase text-muted mb-1" style="font-size:10px;">Quantity</p>
                    <p class="h3 fw-bold mb-0 text-warning" style="color:#b58100 !important;"><?= e($qtyDisplay) ?></p>
                </div>
            </div>
        </div>

        <div class="rounded-3 border p-3 small mb-3">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-muted">Payment status</span>
                <?php if ($paymentNotice !== null): ?>
                <span class="badge text-bg-<?= e($paymentNotice['level']) ?>"><?= e(ucfirst($paymentStatus)) ?></span>
                <?php else: ?>
                <span class="badge text-bg-secondary"><?= e(ucfirst($paymentStatus)) ?></span>
                <?php endif; ?>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-muted">Reviewed by</span>
                <span class="fw-semibold text-end"><?= e($voucher->reviewer_name ?? '—') ?></span>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-0">
                <span class="text-muted">Approved by</span>
                <span class="fw-semibold text-end"><?= e($voucher->approver_name_full ?? '—') ?></span>
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
