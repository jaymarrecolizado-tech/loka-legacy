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

if ($failed > 0) {
    fwrite(STDERR, "{$failed} failed\n");
    exit(1);
}
echo "all passed\n";
