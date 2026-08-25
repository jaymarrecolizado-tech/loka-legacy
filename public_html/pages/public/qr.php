<?php
/**
 * LOKA - Public QR Code Generator API
 * Used by printed gas vouchers so stations can scan authenticity links.
 *
 * Prefers PNG when GD is available; falls back to SVG otherwise.
 */

require_once BASE_PATH . '/vendor/tecnickcom/tcpdf/tcpdf_barcodes_2d.php';

$data = $_GET['data'] ?? '';

if (!is_string($data) || $data === '' || strlen($data) > 2048) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Invalid request';
    exit;
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

$barcodeobj = new TCPDF2DBarcode($data, 'QRCODE,H');

if (function_exists('imagecreate')) {
    $barcodeobj->getBarcodePNG(5, 5, [0, 0, 0]);
    exit;
}

// Fallback when php_gd is not loaded
header('Content-Type: image/svg+xml; charset=UTF-8');
header('Cache-Control: public, max-age=300');
$svgOut = $barcodeobj->getBarcodeSVGcode(4, 4, 'black');
// Inject viewBox so browsers can scale the SVG without clipping the QR modules.
if (preg_match('/<svg[^>]+width="([\d.]+)"[^>]+height="([\d.]+)"/i', $svgOut, $m)) {
    $svgOut = preg_replace('/<svg([^>]+)>/i', '<svg$1 viewBox="0 0 ' . $m[1] . ' ' . $m[2] . '">', $svgOut, 1);
}
echo $svgOut;
exit;


