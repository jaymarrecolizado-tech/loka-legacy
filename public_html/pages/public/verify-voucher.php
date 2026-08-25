<?php
/**
 * LOKA - Public Gas Voucher Validation Page
 *
 * Opened when a gasoline station scans the QR code on a printed voucher.
 * Confirms authenticity via HMAC hash and that the voucher is approved.
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Gas Voucher | <?= e(APP_NAME) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background: linear-gradient(160deg, #e8eef5 0%, #d5e0ec 100%); min-height: 100vh; }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center p-4">

<div class="w-100 max-w-md bg-white rounded-2xl shadow-sm-xl overflow-hidden my-6">
    <div class="bg-[#0b3d6e] text-white p-5 text-center">
        <p class="small uppercase tracking-widest text-blue-200 mb-1">DICT Region II</p>
        <h1 class="fs-4 fw-bold tracking-wide">Gas Voucher Check</h1>
        <p class="text-blue-200 small mt-1">Scan result for gasoline station</p>
    </div>

    <div class="p-5">
        <?php if (!$isValid): ?>
            <div class="rounded-xl bg-red-600 text-white p-4 d-flex gap-3 align-items-start mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 d-flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                <div>
                    <h2 class="fw-bold fs-5 leading-tight">Not Valid</h2>
                    <p class="small mt-1 text-red-100"><?= e($error ?? 'Unable to verify this voucher.') ?></p>
                </div>
            </div>
            <p class="text-center small text-gray-500">
                Do not release fuel for this document. Contact DICT Region II Motor Pool if needed.
            </p>
        <?php else: ?>
            <div class="rounded-xl bg-emerald-600 text-white p-4 d-flex gap-3 align-items-center mb-5">
                <div class="bg-white/20 rounded-full p-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                </div>
                <div>
                    <h2 class="fw-bold fs-4 leading-tight">AUTHENTIC</h2>
                    <p class="text-emerald-100 small">Approved for fuel / item release</p>
                </div>
            </div>

            <div class="d-row gap-2">
                <div class="rounded-xl border border-gray-200 p-4 d-flex justify-content-between gap-3">
                    <div>
                        <p class="text-[11px] uppercase tracking-wide text-gray-500 fw-semibold">Voucher No.</p>
                        <p class="fs-4 fw-bold text-red-600"><?= e($voucher->voucher_no) ?></p>
                    </div>
                    <div class="text-right">
                        <p class="text-[11px] uppercase tracking-wide text-gray-500 fw-semibold">Date</p>
                        <p class="fw-semibold text-gray-900"><?= e(date('M d, Y', strtotime($voucher->request_date))) ?></p>
                    </div>
                </div>

                <?php if (!empty($voucher->gas_station)): ?>
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                    <p class="text-[11px] uppercase tracking-wide text-amber-800/70 fw-semibold">Addressed To</p>
                    <p class="fw-bold text-amber-950 mt-0.5"><?= e($voucher->gas_station) ?></p>
                </div>
                <?php endif; ?>

                <div class="rounded-xl border border-blue-100 bg-blue-50/60 p-4 d-row gap-2">
                    <p class="small fw-bold text-blue-900 border-b border-blue-100 pb-2">Bearer & Vehicle</p>
                    <div class="row grid-cols-[100px_1fr] gap-y-1 small">
                        <span class="text-gray-500">Driver</span>
                        <span class="fw-bold text-gray-900"><?= e(strtoupper($voucher->driver_name)) ?></span>
                        <span class="text-gray-500">Plate No.</span>
                        <span class="fw-bold text-gray-900"><?= e(strtoupper($voucher->vehicle_plate)) ?></span>
                    </div>
                </div>

                <div class="rounded-xl border border-orange-100 bg-orange-50/60 p-4">
                    <p class="small fw-bold text-orange-900 border-b border-orange-100 pb-2 mb-2">Authorized Items</p>
                    <div class="d-flex justify-content-between items-end">
                        <div>
                            <p class="text-[11px] uppercase text-gray-500">Fuel / Article</p>
                            <p class="fs-5 fw-bold text-gray-900"><?= e(strtoupper($voucher->fuel_type)) ?></p>
                            <?php if (!empty($voucher->other_items)): ?>
                            <p class="small text-gray-700 mt-1">+ <?= e($voucher->other_items) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="text-right">
                            <p class="text-[11px] uppercase text-gray-500">Quantity</p>
                            <p class="fs-3 font-black text-orange-600"><?= e($qtyDisplay) ?></p>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-gray-200 p-4 small d-row gap-2">
                    <div class="d-flex justify-content-between gap-2">
                        <span class="text-gray-500">Reviewed by</span>
                        <span class="fw-semibold text-right"><?= e($voucher->reviewer_name ?? 'â€”') ?></span>
                    </div>
                    <div class="d-flex justify-content-between gap-2">
                        <span class="text-gray-500">Approved by</span>
                        <span class="fw-semibold text-right"><?= e($voucher->approver_name_full ?? 'â€”') ?></span>
                    </div>
                </div>
            </div>

            <p class="mt-4 text-center small text-gray-400">
                Official verification Â· DICT Region II Â· LOKA Fleet<br>
                Scanned <?= e(date('M d, Y h:i A')) ?>
            </p>
        <?php endif; ?>
    </div>
</div>

</body>
</html>

