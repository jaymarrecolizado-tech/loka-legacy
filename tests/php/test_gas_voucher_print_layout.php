<?php
/**
 * Gas voucher print is mandated as three copies on one 8.5×13 in sheet.
 */
require_once dirname(__DIR__, 2) . '/public_html/includes/gas_voucher_print.php';

$failed = 0;
function expect($label, $got, $want): void
{
    global $failed;
    if ($got !== $want) {
        fwrite(STDERR, "FAIL {$label}: got " . var_export($got, true) . " want " . var_export($want, true) . "\n");
        $failed++;
        return;
    }
    echo "ok {$label}\n";
}

foreach (['dual', 'triple', 'single', 'folio', '', null] as $raw) {
    $got = gasVoucherPrintLayout($raw);
    $tag = $raw === null ? 'null' : ($raw === '' ? 'empty' : (string) $raw);
    expect("{$tag} layout", $got['layout'], 'triple');
    expect("{$tag} copies", $got['copyCount'], 3);
    expect("{$tag} page", $got['pageCssSize'], '8.5in 13in');
    expect("{$tag} one sheet", $got['copiesOnOneSheet'], true);
}

expect('litters', gasVoucherNormalizeUnit('litters'), 'liters');
expect('Litter', gasVoucherNormalizeUnit('Litter'), 'liters');
expect('litres', gasVoucherNormalizeUnit('litres'), 'liters');
expect('FULL TANK keep', gasVoucherNormalizeUnit('FULL TANK'), 'FULL TANK');
expect('empty unit', gasVoucherNormalizeUnit(null), '');
expect('ml to ML', gasVoucherNormalizeUnit('ml'), 'ML');
expect('milliliters', gasVoucherNormalizeUnit('milliliters'), 'ML');

$extraVoucher = (object) [
    'other_qty' => 2,
    'other_unit' => 'litters',
    'other_items' => 'Engine oil',
    'other_qty_2' => 270,
    'other_unit_2' => 'ml',
    'other_items_2' => 'Brake fluid',
];
$extras = gasVoucherExtraArticles($extraVoucher);
expect('extra count', count($extras), 2);
expect('extra1 item', $extras[0]['item'], 'Engine oil');
expect('extra1 unit', $extras[0]['unit'], 'liters');
expect('extra2 item', $extras[1]['item'], 'Brake fluid');
expect('extra2 unit', $extras[1]['unit'], 'ML');
expect('extra2 qty', $extras[1]['qty'], 270);
expect('empty path', gasVoucherEsignSrc(null), null);
expect('blank path', gasVoucherEsignSrc(''), null);
expect('reject traversal', gasVoucherEsignSrc('uploads/../.env'), null);
expect('reject non-uploads', gasVoucherEsignSrc('public_html/.env'), null);
expect('missing file', gasVoucherEsignSrc('uploads/user_signatures/no-such-file.png'), null);

$base = sys_get_temp_dir() . '/gv_esign_' . bin2hex(random_bytes(4));
$dir = $base . '/uploads/user_signatures';
mkdir($dir, 0777, true);
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $base);
}
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=');
file_put_contents($dir . '/1.png', $png);
$src = gasVoucherEsignSrc('uploads/user_signatures/1.png');
expect('png data uri prefix', is_string($src) && str_starts_with($src, 'data:image/png;base64,'), true);

if ($failed > 0) {
    fwrite(STDERR, "{$failed} failed\n");
    exit(1);
}
echo "all passed\n";
