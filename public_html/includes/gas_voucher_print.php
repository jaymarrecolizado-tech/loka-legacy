<?php
/**
 * Print layout for approved gas vouchers.
 *
 * dual   — two copies on one A4 sheet (default)
 * triple — three copies on one 8.5×13 in folio / long-bond sheet
 * single — one copy per A4 page
 */

function gasVoucherPrintLayout(?string $raw): array
{
    $layout = strtolower(trim((string) $raw));
    if (!in_array($layout, ['dual', 'triple', 'single'], true)) {
        $layout = 'dual';
    }

    return [
        'layout' => $layout,
        'copyCount' => $layout === 'triple' ? 3 : 2,
        'pageCssSize' => $layout === 'triple' ? '8.5in 13in' : 'A4',
        'copiesOnOneSheet' => $layout !== 'single',
    ];
}
