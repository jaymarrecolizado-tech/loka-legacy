<?php
/**
 * Print layout for approved gas vouchers.
 * Mandated: three copies on one 8.5×13 in folio / long-bond sheet.
 */

function gasVoucherPrintLayout(?string $raw = null): array
{
    return [
        'layout' => 'triple',
        'copyCount' => 3,
        'pageCssSize' => '8.5in 13in',
        'copiesOnOneSheet' => true,
    ];
}
