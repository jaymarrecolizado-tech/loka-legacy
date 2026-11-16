<?php
/**
 * Gas voucher print layout: dual A4, triple 8.5×13 (3 copies / sheet), single.
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

$dual = gasVoucherPrintLayout('dual');
expect('dual layout', $dual['layout'], 'dual');
expect('dual copies', $dual['copyCount'], 2);
expect('dual page', $dual['pageCssSize'], 'A4');
expect('dual one sheet', $dual['copiesOnOneSheet'], true);

$triple = gasVoucherPrintLayout('triple');
expect('triple layout', $triple['layout'], 'triple');
expect('triple copies', $triple['copyCount'], 3);
expect('triple page', $triple['pageCssSize'], '8.5in 13in');
expect('triple one sheet', $triple['copiesOnOneSheet'], true);

$single = gasVoucherPrintLayout('SINGLE');
expect('single layout', $single['layout'], 'single');
expect('single copies', $single['copyCount'], 2);
expect('single not one sheet', $single['copiesOnOneSheet'], false);

$bad = gasVoucherPrintLayout('folio');
expect('unknown falls back to dual', $bad['layout'], 'dual');
expect('empty falls back to dual', gasVoucherPrintLayout('')['layout'], 'dual');
expect('null falls back to dual', gasVoucherPrintLayout(null)['layout'], 'dual');

if ($failed > 0) {
    fwrite(STDERR, "{$failed} failed\n");
    exit(1);
}
echo "all passed\n";
