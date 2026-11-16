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
            approver.name AS approver_name_full,
            budget_officer.name AS budget_officer_name
     FROM gas_vouchers gv
     JOIN users u ON gv.requested_by_user_id = u.id
     LEFT JOIN users reviewer ON gv.reviewed_by = reviewer.id
     LEFT JOIN users approver ON gv.approved_by = approver.id
     LEFT JOIN users budget_officer ON gv.budget_reviewed_by = budget_officer.id
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

require_once INCLUDES_PATH . '/gas_voucher_print.php';
$printOpts = gasVoucherPrintLayout();
$printLayout = $printOpts['layout'];
$copyCount = $printOpts['copyCount'];

// QR Verification -- gas stations scan this to confirm authenticity
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
        // TCPDF marks white as transparent -- flatten onto opaque white + quiet zone
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

        /* Header block — logos left/right, agency text centered */
        .grid-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 20px;
        }
        .header-logo {
            width: 100px;
            height: auto;
            flex-shrink: 0;
            object-fit: contain;
            mix-blend-mode: multiply; /* Fixes transparency/white background issues on print */
        }
        .header-text {
            flex: 1;
            text-align: center;
            min-width: 0;
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
            width: 25%;
            vertical-align: top;
        }

        .sig-stack {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 52px;
            height: 100%;
        }
        .sig-signed {
            margin-top: auto;
        }
        .sig-label {
            font-size: 9pt;
            color: #333;
            margin-bottom: 0;
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

        /* 3-up: one 8.5×13 sheet, three equal slots, voucher scaled to fill each slot */
        body.layout-triple {
            background: #e9ecef;
        }
        body.layout-triple .sheet {
            width: 8.5in;
            height: 13in;
            margin: 0 auto 24px;
            background: #fff;
            box-shadow: 0 0 0 1px #ccc;
            padding: 5mm 6mm;
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
        }
        body.layout-triple .page {
            width: 100%;
            flex: 1 1 0;
            min-height: 0;
            height: auto;
            padding: 0;
            margin: 0;
            overflow: hidden;
            position: relative;
        }
        body.layout-triple .page-fit {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            transform-origin: top left;
        }
        body.layout-triple .page-fit > .voucher-row,
        body.layout-triple .page-fit > .grid-header,
        body.layout-triple .page-fit > .header-title,
        body.layout-triple .page-fit > .gas-station-title {
            flex: 0 0 auto;
        }
        body.layout-triple .page-fit > .signatures-row {
            flex: 1 1 auto;
        }
        body.layout-triple .signatures-row td {
            height: 16mm;
            vertical-align: top;
            padding: 2px 4px 3px;
        }
        body.layout-triple .sig-stack {
            min-height: 14mm;
            height: 100%;
            justify-content: space-between;
        }
        body.layout-triple .sig-signed {
            margin-top: auto;
        }
        body.layout-triple .page-fit > .footer-row {
            flex: 0 0 auto;
            margin-top: 4px !important;
        }
        body.layout-triple .cut-line-separator {
            flex: 0 0 5mm;
            margin: 0;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 7pt;
            letter-spacing: 2px;
        }
        body.layout-triple .grid-header { margin-bottom: 4px; gap: 8px; }
        body.layout-triple .header-logo { width: 48px; }
        body.layout-triple .header-text .republic { font-size: 8pt; }
        body.layout-triple .header-text .dept { font-size: 9.5pt; margin: 1px 0; }
        body.layout-triple .header-text .office { font-size: 7.5pt; }
        body.layout-triple .header-title { margin: 2px 0 3px; font-size: 12pt; letter-spacing: 3px; }
        body.layout-triple .gas-station-title { font-size: 11pt; margin-bottom: 3px; }
        body.layout-triple .voucher-row td,
        body.layout-triple .voucher-row th { padding: 3px 6px; font-size: 8.5pt; }
        body.layout-triple .auth-row { padding: 4px 8px !important; font-size: 8.5pt; }
        body.layout-triple .sig-label { margin-bottom: 0; font-size: 7.5pt; }
        body.layout-triple .sig-name { font-size: 9pt; }
        body.layout-triple .sig-title { font-size: 7.5pt; }
        body.layout-triple .footer-row { margin-top: 4px !important; }
        body.layout-triple .qr-block .qr-img { width: 72px; height: 72px; }
        body.layout-triple .qr-block .qr-svg-pad svg { width: 64px; height: 64px; }

        @media print {
            <?php if ($printLayout === 'triple'): ?>
            @page { size: 8.5in 13in portrait; margin: 0; }
            <?php else: ?>
            @page { size: A4 portrait; margin: 6mm; }
            <?php endif; ?>
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
            .grid-header { display: flex !important; align-items: center !important; justify-content: space-between !important; gap: 6px !important; margin-bottom: 2px !important; }
            .header-logo { width: 48px !important; height: auto !important; flex-shrink: 0 !important; }
            .header-text { flex: 1 !important; }
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
            .sig-stack { min-height: 36px; }
            .sig-label { margin-bottom: 0 !important; font-size: 7.5pt !important; }
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
            .footer-row { display: flex; justify-content: space-between; align-items: flex-end; gap: 8px; margin-top: 3px !important; }
            body.layout-single .page { page-break-after: always; break-after: page; }
            body.layout-single .page:last-of-type { page-break-after: auto; break-after: auto; }
            body.layout-single .cut-line-separator { display: none !important; }
            html, body.layout-triple {
                width: 8.5in !important;
                height: 13in !important;
                background: #fff !important;
            }
            body.layout-triple .sheet {
                width: 8.5in !important;
                height: 13in !important;
                margin: 0 !important;
                padding: 4mm 5mm !important;
                box-shadow: none !important;
                page-break-after: avoid;
            }
            body.layout-triple .page {
                page-break-inside: avoid;
                break-inside: avoid;
                flex: 1 1 0;
                min-height: 0 !important;
                height: auto !important;
                width: 100% !important;
                padding: 0 !important;
                overflow: hidden;
            }
            body.layout-triple .cut-line-separator {
                flex: 0 0 4.5mm;
                margin: 0 !important;
                padding: 0 !important;
                font-size: 6.5pt !important;
            }
            body.layout-triple .grid-header { margin-bottom: 2px !important; gap: 6px !important; }
            body.layout-triple .header-logo { width: 42px !important; }
            body.layout-triple .header-text .republic { font-size: 7.5pt !important; }
            body.layout-triple .header-text .dept { font-size: 9pt !important; }
            body.layout-triple .header-text .office { font-size: 7pt !important; }
            body.layout-triple .header-title { margin: 1px 0 2px !important; font-size: 11pt !important; letter-spacing: 2px !important; }
            body.layout-triple .gas-station-title { font-size: 10pt !important; margin-bottom: 2px !important; }
            body.layout-triple .voucher-row,
            body.layout-triple .voucher-row td,
            body.layout-triple .voucher-row th { font-size: 8pt !important; padding: 2px 4px !important; }
            body.layout-triple .auth-row { padding: 3px 6px !important; font-size: 8pt !important; }
            body.layout-triple .article-row td { padding: 3px 4px !important; font-size: 8.5pt !important; }
            body.layout-triple .signatures-row td { padding: 2px 3px 2px !important; height: 15mm !important; vertical-align: top !important; }
            body.layout-triple .sig-stack { min-height: 13mm !important; height: 100% !important; }
            body.layout-triple .sig-label { margin-bottom: 0 !important; font-size: 7pt !important; }
            body.layout-triple .sig-name { font-size: 8.5pt !important; }
            body.layout-triple .sig-title { font-size: 7pt !important; }
            body.layout-triple .distribution { margin-top: 2px !important; font-size: 7pt !important; }
            body.layout-triple .purpose-cell { max-height: 2.2em !important; font-size: 8pt !important; }
            body.layout-triple .qr-block .qr-img { width: 58px !important; height: 58px !important; }
            body.layout-triple .qr-block .qr-svg-pad { padding: 4px !important; }
            body.layout-triple .qr-block .qr-svg-pad svg { width: 52px !important; height: 52px !important; }
            body.layout-triple .qr-block .qr-label { font-size: 6.5pt !important; margin-top: 2px !important; }
            body.layout-triple .qr-block .qr-sub { font-size: 6pt !important; }
            body.layout-triple .footer-row { margin-top: 3px !important; }
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
            padding: 14px; /* quiet zone -- do not put text here */
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

        .folio-modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            font-family: sans-serif;
        }
        .folio-modal-backdrop[hidden] { display: none !important; }
        .folio-modal {
            background: #fff;
            max-width: 480px;
            width: 100%;
            border-radius: 10px;
            box-shadow: 0 16px 40px rgba(0,0,0,.25);
            padding: 22px 24px 18px;
            color: #212529;
            text-align: left;
        }
        .folio-modal h2 {
            margin: 0 0 10px;
            font-size: 18px;
            color: #084298;
        }
        .folio-modal p { margin: 0 0 10px; font-size: 14px; line-height: 1.45; }
        .folio-modal .folio-size {
            display: block;
            font-size: 20px;
            font-weight: 700;
            color: #0d6efd;
            margin: 8px 0 12px;
        }
        .folio-modal .folio-actions {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            margin-top: 16px;
        }
        .folio-modal button {
            border: none;
            border-radius: 6px;
            padding: 8px 16px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }
        .folio-btn-secondary { background: #e9ecef; color: #212529; }
        .folio-btn-primary { background: #0d6efd; color: #fff; }
        @media print {
            .folio-modal-backdrop { display: none !important; }
        }
    </style>
</head>
<body class="layout-<?= e($printLayout) ?>">

<div id="folioPaperModal" class="folio-modal-backdrop no-print" role="dialog" aria-modal="true" aria-labelledby="folioPaperTitle">
    <div class="folio-modal">
        <h2 id="folioPaperTitle">Use 8.5×13 in / folio paper</h2>
        <p>Gas vouchers print as <strong>three copies on one sheet</strong>. Please load:</p>
        <span class="folio-size">8.5 × 13 inches (Folio / long bond)</span>
        <p>In the print dialog set paper to <strong>8.5 × 13 in</strong>, not A4 or Legal 14 in. Margins <strong>None</strong>, scale <strong>100% / Actual size</strong>.</p>
        <div class="folio-actions">
            <button type="button" class="folio-btn-secondary" id="folioModalDismiss">Got it</button>
            <button type="button" class="folio-btn-primary" id="folioModalPrint">Print now</button>
        </div>
    </div>
</div>

<!-- Print Controls -->
<div class="no-print" style="text-align:center;padding:12px;background:#f8f9fa;border-bottom:1px solid #ddd;margin-bottom:15px;font-family:sans-serif;">
    <button type="button" id="folioPrintBtn" style="background:#0d6efd;color:#fff;border:none;padding:8px 24px;border-radius:4px;cursor:pointer;font-size:14px;font-weight:bold;">
        Print Voucher (8.5×13)
    </button>
    <a href="javascript:history.back()" style="margin-left:15px;color:#666;text-decoration:none;">&larr; Back to App</a>
    <div style="margin-top:8px;font-size:13px;color:#084298;">Three copies on one sheet. Paper: <strong>8.5 × 13 in</strong> (Folio / long bond).</div>
    <div style="margin-top:10px;padding:8px 12px;background:#e7f1ff;border:1px solid #9ec5fe;border-radius:6px;display:inline-block;max-width:720px;font-size:13px;color:#084298;text-align:left;">
        <strong>Phone scan tip:</strong> Join the same Wi-Fi as this PC, then scan again.<br>
        Verify link: <code style="word-break:break-all;"><?= e($verifyUrl) ?></code>
        <?php if ($siteLooksLocal): ?>
        <br><span style="color:#664d03;">Still using localhost -- phones cannot open this. Set <code>SITE_URL</code> in <code>.env</code> to your LAN IP or public domain.</span>
        <?php endif; ?>
    </div>
</div>

<div class="sheet">
<?php for ($copy = 1; $copy <= $copyCount; $copy++): ?>
<?php if ($copy > 1 && $printLayout !== 'single'): ?>
    <div class="cut-line-separator">CUT HERE</div>
<?php endif; ?>
<div class="page">
<div class="page-fit">

    <!-- Formal Header -->
    <div class="grid-header">
        <img src="<?= APP_URL ?>/assets/img/dict_logo.png" class="header-logo" alt="DICT Logo" onerror="this.style.display='none'">
        <div class="header-text">
            <div class="republic">Republic of the Philippines</div>
            <div class="dept">Department of Information and Communications Technology</div>
            <div class="office">Regional Office 02, 02 Bagay Road, San Gabriel, Tuguegarao City, Cagayan 3500</div>
        </div>
        <img src="<?= APP_URL ?>/assets/img/bp_logo.png" class="header-logo" alt="BP Logo" onerror="this.style.display='none'">
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
        $printQty = $isFullTank ? '-' : e($voucher->quantity);
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
                <div class="sig-stack">
                    <div class="sig-label">Requested by / Bearer:</div>
                    <div class="sig-signed">
                        <div class="sig-name"><?= e(strtoupper($voucher->driver_name)) ?></div>
                        <div class="sig-title">Driver</div>
                    </div>
                </div>
            </td>
            <td>
                <div class="sig-stack">
                    <div class="sig-label">Reviewed by:</div>
                    <div class="sig-signed">
                        <div class="sig-name"><?= e(strtoupper($voucher->reviewer_name ?? 'ENGR. RONALD S. BARIUAN')) ?></div>
                        <div class="sig-title">OIC, Motor Pool Unit</div>
                    </div>
                </div>
            </td>
            <td>
                <div class="sig-stack">
                    <div class="sig-label">Certified by:</div>
                    <div class="sig-signed">
                        <div class="sig-name"><?= e(strtoupper($voucher->budget_officer_name ?? '')) ?></div>
                        <div class="sig-title">Budget Officer</div>
                    </div>
                </div>
            </td>
            <td>
                <div class="sig-stack">
                    <div class="sig-label">Approved by:</div>
                    <div class="sig-signed">
                        <div class="sig-name"><?= e(strtoupper($voucher->approver_name_full ?? 'MINA FLOR T. VILLAFUERTE')) ?></div>
                        <div class="sig-title">Chief, Admin. and Finance Division</div>
                    </div>
                </div>
            </td>
        </tr>
    </table>

    
    <div class="footer-row" style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 10px;">
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
</div>
<?php endfor; ?>
</div>
<script>
(function () {
    function fitTriplePages() {
        document.querySelectorAll('body.layout-triple .page').forEach(function (slot) {
            var inner = slot.querySelector('.page-fit');
            if (!inner) return;
            inner.style.transform = 'none';
            var cw = slot.clientWidth;
            var ch = slot.clientHeight;
            var sw = inner.scrollWidth;
            var sh = inner.scrollHeight;
            if (cw < 8 || ch < 8 || sw < 8 || sh < 8) return;
            var scale = Math.min(cw / sw, ch / sh, 1);
            inner.style.transformOrigin = 'top left';
            inner.style.transform = (scale < 0.995) ? ('scale(' + scale + ')') : 'none';
        });
    }
    var modal = document.getElementById('folioPaperModal');
    function showFolioModal() {
        if (modal) modal.removeAttribute('hidden');
    }
    function hideFolioModal() {
        if (modal) modal.setAttribute('hidden', 'hidden');
        setTimeout(fitTriplePages, 50);
    }
    var dismiss = document.getElementById('folioModalDismiss');
    var printNow = document.getElementById('folioModalPrint');
    var printBtn = document.getElementById('folioPrintBtn');
    if (dismiss) dismiss.addEventListener('click', hideFolioModal);
    if (printNow) printNow.addEventListener('click', function () {
        hideFolioModal();
        setTimeout(function () { window.print(); }, 80);
    });
    if (printBtn) printBtn.addEventListener('click', showFolioModal);
    showFolioModal();
    window.addEventListener('load', fitTriplePages);
    window.addEventListener('resize', fitTriplePages);
    if (window.matchMedia) {
        window.matchMedia('print').addListener(fitTriplePages);
    }
    window.addEventListener('beforeprint', fitTriplePages);
    window.addEventListener('afterprint', fitTriplePages);
    setTimeout(fitTriplePages, 50);
    setTimeout(fitTriplePages, 300);
})();
</script>
</body>
</html>

