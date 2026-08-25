<?php
/**
 * LOKA - Gas Voucher Print Page
 * Generates a printable gas voucher matching the official DICT RO2 format.
 */

$voucherId = (int) get('id', 0);
if (!$voucherId) {
    redirectWith('/?page=gas-vouchers', 'danger', 'Invalid voucher.');
}

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
    [$voucherId]
);

if (!$voucher) {
    redirectWith('/?page=gas-vouchers', 'danger', 'Voucher not found.');
}

if ($voucher->status !== 'approved') {
    redirectWith('/?page=gas-vouchers&action=view&id=' . $voucherId, 'warning', 'Only approved vouchers can be printed.');
}

// Access control
if ($voucher->requested_by_user_id != userId() && !isAdmin() && !isApprover() && !isMotorpool() && !isChiefAdminFinance()) {
    redirectWith('/?page=gas-vouchers', 'danger', 'Access denied.');
}

// dual = 2 copies on one A4 (default); single = 1 copy per A4 page (fallback)
$printLayout = strtolower((string) get('layout', 'dual'));
if (!in_array($printLayout, ['dual', 'single'], true)) {
    $printLayout = 'dual';
}
$copyCount = 2;

// QR Verification â€” gas stations scan this to confirm authenticity
$verifyUrl = gasVoucherVerifyUrl($voucher);
$siteLooksLocal = (bool) preg_match('#://(localhost|127\.0\.0\.1)([:/]|$)#i', $verifyUrl);

// Crisp QR with quiet zone: prefer PNG (solid white pad); SVG fallback
require_once BASE_PATH . '/vendor/tecnickcom/tcpdf/tcpdf_barcodes_2d.php';
$qrBarcode = new TCPDF2DBarcode($verifyUrl, 'QRCODE,M');
$qrHtml = '';
$pngRaw = function_exists('imagecreate') ? $qrBarcode->getBarcodePngData(6, 6, [0, 0, 0]) : false;

if (is_string($pngRaw) && $pngRaw !== '' && function_exists('imagecreatefromstring')) {
    $src = @imagecreatefromstring($pngRaw);
    if ($src !== false) {
        // TCPDF marks white as transparent â€” flatten onto opaque white + quiet zone
        $sw = imagesx($src);
        $sh = imagesy($src);
        $pad = (int) max(28, round(min($sw, $sh) * 0.14));
        $flat = imagecreatetruecolor($sw, $sh);
        $whiteFlat = imagecolorallocate($flat, 255, 255, 255);
        imagefilledrectangle($flat, 0, 0, $sw, $sh, $whiteFlat);
        imagecopy($flat, $src, 0, 0, 0, 0, $sw, $sh);
        imagedestroy($src);

        $dst = imagecreatetruecolor($sw + ($pad * 2), $sh + ($pad * 2));
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $sw + ($pad * 2), $sh + ($pad * 2), $white);
        imagecopy($dst, $flat, $pad, $pad, 0, 0, $sw, $sh);
        imagedestroy($flat);
        ob_start();
        imagepng($dst);
        $padded = ob_get_clean();
        imagedestroy($dst);
        $qrHtml = '<img class="qr-img" alt="Verify QR" width="148" height="148" src="data:image/png;base64,'
            . base64_encode($padded) . '">';
    }
}

if ($qrHtml === '') {
    $qrSvg = $qrBarcode->getBarcodeSVGcode(4, 4, 'black');
    $qrSvg = preg_replace('/<\?xml[^>]*\?>\s*/i', '', $qrSvg);
    $qrSvg = preg_replace('/<!DOCTYPE[^>]*>\s*/i', '', $qrSvg);
    // Inject viewBox so the SVG scales correctly when CSS sets width/height.
    // Without viewBox the browser clips the content instead of scaling it,
    // making the QR code unscannable on mobile devices.
    if (preg_match('/<svg[^>]+width="([\d.]+)"[^>]+height="([\d.]+)"/i', $qrSvg, $svgDims)) {
        $svgW = $svgDims[1];
        $svgH = $svgDims[2];
        $qrSvg = preg_replace(
            '/<svg([^>]+)>/i',
            '<svg$1 viewBox="0 0 ' . $svgW . ' ' . $svgH . '">',
            $qrSvg,
            1
        );
    }
    $qrHtml = '<div class="qr-svg-pad">' . $qrSvg . '</div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gas Voucher <?= e($voucher->voucher_no) ?> - DICT RO2</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Arial', sans-serif;
            font-size: 11pt;
            background: #fff;
            color: #000;
        }

        .page {
            width: 210mm;
            min-height: 140mm;
            margin: 0 auto;
            padding: 15mm;
            position: relative;
        }

        /* Header block */
        .grid-header {
            display: d-flex;
            align-items: center;
            margin-bottom: 20px;
        }
        .header-logo {
            width: 100px;
            object-fit: contain;
            mix-blend-mode: multiply; /* Fixes transparency/white background issues on print */
        }
        .header-text {
            d-flex: 1;
            text-align: center;
        }
        .header-text .republic {
            font-size: 10pt;
            font-family: 'Times New Roman', Times, serif;
        }
        .header-text .dept {
            font-size: 12pt;
            font-weight: bold;
            font-family: 'Times New Roman', Times, serif;
            text-transform: uppercase;
            margin: 3px 0;
        }
        .header-text .office {
            font-size: 10pt;
            font-family: 'Times New Roman', Times, serif;
        }
        
        .header-title {
            font-size: 18pt;
            font-weight: bold;
            text-align: center;
            letter-spacing: 5px;
            margin: 25px 0 15px;
            font-family: 'Arial', sans-serif;
        }

        .gas-station-title {
            font-size: 16pt;
            margin-bottom: 10px;
            margin-left: 5px;
            font-family: 'Arial', sans-serif;
            text-align: left;
        }

        /* Form row Table */
        .voucher-row {
            width: 100%;
            border-collapse: collapse;
            border: 2px solid #000;
            font-size: 10.5pt;
        }

        .voucher-row td, .voucher-row th {
            border: 1px solid #000;
            padding: 6px 10px;
            vertical-align: middle;
        }

        .voucher-row .label-cell {
            background-color: #fce4d6; /* Very light orange/pink typical in sheets */
            -webkit-print-color-adjust: exact;
            color-adjust: exact;
            print-color-adjust: exact;
            font-weight: bold;
            width: 20%;
        }

        .voucher-row .value-cell {
            width: 30%;
            font-weight: bold;
        }

        /* Authorization Row */
        .auth-row {
            text-align: center;
            font-style: italic;
            padding: 12px !important;
            font-size: 11pt;
        }

        /* Articles Table Headers */
        .articles-header th {
            background-color: #d9e1f2; /* Light blue typical in sheets */
            -webkit-print-color-adjust: exact;
            color-adjust: exact;
            print-color-adjust: exact;
            text-align: center;
            font-weight: bold;
            padding: 8px;
        }

        .center {
            text-align: center;
        }

        /* Signatures row */
        .signatures-row {
            width: 100%;
            border-collapse: collapse;
            border: 2px solid #000;
            border-top: none;
            text-align: center;
        }

        .signatures-row td {
            border: 1px solid #000;
            padding: 10px;
            width: 33.33%;
            vertical-align: top;
        }

        .sig-label {
            font-size: 9pt;
            color: #333;
            margin-bottom: 40px;
            text-align: left;
        }

        .sig-name {
            font-weight: bold;
            font-size: 11pt;
            text-transform: uppercase;
            text-decoration: underline;
        }

        .sig-title {
            font-size: 9pt;
            margin-top: 3px;
        }

        .distribution {
            margin-top: 20px;
            font-size: 9pt;
            font-style: italic;
        }

        .purpose-cell {
            max-height: 3.2em;
            overflow: hidden;
        }

        .article-row td {
            font-weight: bold;
        }

        .cut-line-separator {
            text-align: center;
            padding: 4px 0;
            margin: 2mm 0;
            border-top: 1px dashed #999;
            border-bottom: 1px dashed #999;
            font-size: 8pt;
            color: #666;
            letter-spacing: 1px;
        }

        .sheet {
            width: 210mm;
            margin: 0 auto;
        }

        @media print {
            @page { size: A4 portrait; margin: 6mm; }
            body { background: #fff; margin: 0; padding: 0; font-size: 9pt; }
            .sheet { width: 100% !important; margin: 0 !important; }
            .page {
                padding: 0 !important;
                min-height: 0 !important;
                margin: 0 !important;
                width: 100% !important;
                page-break-inside: avoid;
                break-inside: avoid;
            }
            .no-print { display: none !important; }
            .grid-header { margin-bottom: 2px !important; }
            .header-logo { width: 48px !important; }
            .header-text .republic { font-size: 8pt !important; }
            .header-text .dept { font-size: 9pt !important; margin: 1px 0 !important; }
            .header-text .office { font-size: 7.5pt !important; }
            .header-title { margin: 2px 0 4px !important; font-size: 12pt !important; letter-spacing: 3px !important; }
            .gas-station-title { font-size: 11pt !important; margin-bottom: 3px !important; margin-left: 2px !important; }
            .voucher-row { font-size: 8.5pt !important; }
            .voucher-row td, .voucher-row th { padding: 2px 4px !important; font-size: 8.5pt !important; }
            .auth-row { padding: 3px 6px !important; font-size: 8.5pt !important; }
            .articles-header th { padding: 2px !important; }
            .article-row td { padding: 3px 4px !important; font-size: 9pt !important; }
            .signatures-row td { padding: 3px !important; }
            .sig-label { margin-bottom: 14px !important; font-size: 7.5pt !important; }
            .sig-name { font-size: 9pt !important; }
            .sig-title { font-size: 7.5pt !important; }
            .distribution { margin-top: 3px !important; font-size: 7pt !important; }
            .purpose-cell {
                max-height: 2.4em !important;
                overflow: hidden !important;
                font-size: 8pt !important;
                line-height: 1.2 !important;
            }
            .qr-block { padding: 2px 4px 4px !important; }
            .qr-block .qr-img { width: 72px !important; height: 72px !important; }
            .qr-block .qr-svg-pad { padding: 6px !important; }
            .qr-block .qr-svg-pad svg { width: 64px !important; height: 64px !important; }
            .qr-block .qr-label { font-size: 7pt !important; }
            .qr-block .qr-sub { font-size: 6.5pt !important; }
            .qr-container { margin-top: 0 !important; }
            .cut-line-separator { padding: 2px 0 !important; margin: 1.5mm 0 !important; font-size: 7pt !important; page-break-inside: avoid; }
            .footer-row { display: d-flex; justify-content: space-between; align-items: d-flex-end; gap: 8px; margin-top: 3px !important; }
            body.layout-single .page { page-break-after: always; break-after: page; }
            body.layout-single .page:last-of-type { page-break-after: auto; break-after: auto; }
            body.layout-single .cut-line-separator { display: none !important; }
        }

        .qr-block {
            text-align: center;
            display: inline-block;
            background: #fff;
            /* Border wraps labels; QR itself sits in a white quiet zone */
            border: 1px solid #999;
            padding: 6px 8px 8px;
        }
        .qr-block .qr-img {
            width: 148px;
            height: 148px;
            display: block;
            margin: 0 auto;
            image-rendering: -webkit-optimize-contrast; /* iOS Safari: crisp pixels */
            image-rendering: pixelated;
            image-rendering: crisp-edges;
            background: #fff;
        }
        .qr-block .qr-svg-pad {
            display: block;
            background: #fff;
            padding: 14px; /* quiet zone â€” do not put text here */
            line-height: 0;
        }
        .qr-block .qr-svg-pad svg {
            width: 112px;
            height: 112px;
            display: block;
            margin: 0 auto;
            shape-rendering: crispEdges;
        }
        .qr-block .qr-label {
            font-size: 8pt;
            font-weight: bold;
            margin-top: 8px; /* clear gap below quiet zone */
            letter-spacing: 0.3px;
        }
        .qr-block .qr-sub {
            font-size: 6.5pt;
            color: #444;
            margin-top: 2px;
        }
    </style>
</head>
<body class="layout-<?= e($printLayout) ?>">

<!-- Print Controls -->
<div class="no-print" style="text-align:center;padding:12px;background:#f8f9fa;border-bottom:1px solid #ddd;margin-bottom:15px;font-family:sans-serif;">
    <button onclick="window.print()" style="background:#0d6efd;color:#fff;border:none;padding:8px 24px;border-radius:4px;cursor:pointer;font-size:14px;font-weight:bold;">
        Print Voucher (A4)
    </button>
    <a href="<?= APP_URL ?>/?page=gas-vouchers&amp;action=print&amp;id=<?= (int) $voucherId ?>&amp;layout=dual" style="margin-left:12px;<?= $printLayout === 'dual' ? 'font-weight:bold;' : '' ?>">2-up (1 page)</a>
    <a href="<?= APP_URL ?>/?page=gas-vouchers&amp;action=print&amp;id=<?= (int) $voucherId ?>&amp;layout=single" style="margin-left:8px;<?= $printLayout === 'single' ? 'font-weight:bold;' : '' ?>">1 copy / page</a>
    <a href="javascript:history.back()" style="margin-left:15px;color:#666;text-decoration:none;">â† Back to App</a>
    <div style="margin-top:10px;padding:8px 12px;background:#e7f1ff;border:1px solid #9ec5fe;border-radius:6px;display:inline-block;max-width:720px;font-size:13px;color:#084298;text-align:left;">
        <strong>Phone scan tip:</strong> Join the same Wiâ€‘Fi as this PC, then scan again.<br>
        Verify link: <code style="word-break:break-all;"><?= e($verifyUrl) ?></code>
        <?php if ($siteLooksLocal): ?>
        <br><span style="color:#664d03;">Still using localhost â€” phones cannot open this. Set <code>SITE_URL</code> in <code>.env</code> to your LAN IP or public domain.</span>
        <?php endif; ?>
    </div>
</div>

<div class="sheet">
<?php for ($copy = 1; $copy <= $copyCount; $copy++): ?>
<?php if ($copy === 2 && $printLayout === 'dual'): ?>
    <div class="cut-line-separator">CUT HERE</div>
<?php endif; ?>
<div class="page">

    <!-- Formal Header -->
    <div class="grid-header">
        <img src="<?= APP_URL ?>/assets/img/dict_logo.png" class="header-logo" alt="DICT Logo" onerror="this.style.display='none'" style="margin-right:15px;">
        <div class="header-text">
            <div class="republic">Republic of the Philippines</div>
            <div class="dept">Department of Information and Communications Technology</div>
            <div class="office">Regional Office 02, 02 Bagay Road, San Gabriel, Tuguegarao City, Cagayan 3500</div>
        </div>
        <img src="<?= APP_URL ?>/assets/img/bp_logo.png" class="header-logo" alt="BP Logo" onerror="this.style.display='none'" style="margin-left:15px;">
    </div>

    <div class="header-title">G A S &nbsp; V O U C H E R</div>
    <div class="gas-station-title">To <strong><?= e($voucher->gas_station ?? '_________________________') ?></strong></div>

    <!-- Main Spreadsheet row -->
    <table class="voucher-row">
        <!-- Authorization Row -->
        <tr>
            <td colspan="4" class="auth-row" style="text-align: left; padding-left: 20px !important;">
                This is to authorize the bearer <strong><?= e(strtoupper($voucher->driver_name)) ?></strong> to withdraw and secure the following products and/or services charge to the Account of <strong style="font-style:normal;text-decoration:underline;">DICT Region 02</strong>.
            </td>
        </tr>

        <tr>
            <td class="label-cell">Date:</td>
            <td class="value-cell"><?= e(date('F j, Y', strtotime($voucher->request_date))) ?></td>
            <td class="label-cell">Voucher No.:</td>
            <td class="value-cell" style="color:red;"><?= e($voucher->voucher_no) ?></td>
        </tr>
        <tr>
            <td class="label-cell">Driver:</td>
            <td class="value-cell"><?= e(strtoupper($voucher->driver_name)) ?></td>
            <td class="label-cell">Vehicle Plate No.:</td>
            <td class="value-cell"><?= e(strtoupper($voucher->vehicle_plate)) ?></td>
        </tr>

        <!-- Articles Headers -->
        <tr class="articles-header">
            <th style="width:15%">QTY</th>
            <th style="width:15%">UNIT</th>
            <th colspan="2">ARTICLES / PARTICULARS</th>
        </tr>
        
        <!-- Articles Data -->
        <?php
        $isFullTank = (strcasecmp((string) $voucher->unit, 'FULL TANK') === 0) || (float) $voucher->quantity <= 0;
        $printQty = $isFullTank ? 'â€”' : e($voucher->quantity);
        $printUnit = $isFullTank ? 'FULL TANK' : e($voucher->unit);
        ?>
        <tr class="article-row">
            <td class="center"><?= $printQty ?></td>
            <td class="center"><?= $printUnit ?></td>
            <td colspan="2" style="vertical-align:middle; color:#000;">
                <?= e($voucher->fuel_type) ?>
            </td>
        </tr>
        <?php if ($voucher->other_items || $voucher->other_qty || $voucher->other_unit): ?>
        <tr class="article-row">
            <td class="center"><?= e($voucher->other_qty) ?></td>
            <td class="center"><?= e($voucher->other_unit) ?></td>
            <td colspan="2" style="vertical-align:middle; color:#000;">
                <?= e($voucher->other_items) ?>
            </td>
        </tr>
        <?php endif; ?>

        <!-- Purpose & Charging -->
        <tr>
            <td class="label-cell">Purpose:</td>
            <td colspan="3" class="value-cell purpose-cell" style="font-weight:normal;"><?= e($voucher->purpose) ?></td>
        </tr>
        <tr>
            <td class="label-cell">Chargeable against:</td>
            <td colspan="3" class="value-cell"><?= e($voucher->chargeable_against ?: $voucher->fund_source) ?></td>
        </tr>
    </table>

    <!-- Signatures section matching the row width -->
    <table class="signatures-row">
        <tr>
            <td>
                <div class="sig-label">Requested by / Bearer:</div>
                <div class="sig-name"><?= e(strtoupper($voucher->driver_name)) ?></div>
                <div class="sig-title">Driver</div>
            </td>
            <td>
                <div class="sig-label">Reviewed by:</div>
                <div class="sig-name"><?= e(strtoupper($voucher->reviewer_name ?? 'ENGR. RONALD S. BARIUAN')) ?></div>
                <div class="sig-title">OIC, Motor Pool Unit</div>
            </td>
            <td>
                <div class="sig-label">Approved by:</div>
                <div class="sig-name"><?= e(strtoupper($voucher->approver_name_full ?? 'MINA FLOR T. VILLAFUERTE')) ?></div>
                <div class="sig-title">Chief, Admin. and Finance Division</div>
            </td>
        </tr>
    </table>

    
    <div class="footer-row" style="display: d-flex; justify-content: space-between; align-items: d-flex-end; margin-top: 10px;">
        <div class="distribution" style="margin-top: 0;">
            
        <strong>Distribution:</strong> Prepare three (3) copies.<br>
        1) Original copy for gas station attached to charge invoice &nbsp;&nbsp;&nbsp;&nbsp; 
        2) Control file copy &nbsp;&nbsp;&nbsp;&nbsp;
        3) Accounting unit copy for reconciliation
    
        </div>
        <div class="qr-container" style="text-align: right;">
            <div class="qr-block">
                <?= $qrHtml ?>
                <div class="qr-label">SCAN TO VERIFY</div>
                <div class="qr-sub"><?= e($voucher->voucher_no) ?></div>
            </div>
        </div>
    </div>

</div>
<?php endfor; ?>
</div>

</body>
</html>

