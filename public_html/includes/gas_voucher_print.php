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

/** Canonical unit labels for gas voucher articles (corrects common misspellings). */
function gasVoucherNormalizeUnit(?string $unit): string
{
    $raw = trim((string) $unit);
    if ($raw === '') {
        return '';
    }
    $key = strtolower(preg_replace('/\s+/', ' ', $raw) ?? $raw);
    $map = [
        'litter' => 'liters',
        'litters' => 'liters',
        'liter' => 'liters',
        'litre' => 'liters',
        'litres' => 'liters',
        'ltr' => 'liters',
        'ltrs' => 'liters',
        'pc' => 'pcs',
        'pcs' => 'pcs',
        'piece' => 'pcs',
        'pieces' => 'pcs',
        'full tank' => 'FULL TANK',
        'fulltank' => 'FULL TANK',
    ];
    return $map[$key] ?? $raw;
}

/**
 * Embed a user specimen as a data URI for print (no extra HTTP during print).
 * Only paths under uploads/ are accepted.
 */
function gasVoucherEsignSrc(?string $relPath): ?string
{
    if ($relPath === null || $relPath === '') {
        return null;
    }
    $relPath = str_replace('\\', '/', $relPath);
    if (!str_starts_with($relPath, 'uploads/') || str_contains($relPath, '..')) {
        return null;
    }
    $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
    $abs = $base . '/' . $relPath;
    if (!is_file($abs)) {
        return null;
    }
    if (defined('APP_URL') && APP_URL !== '') {
        return rtrim((string) APP_URL, '/') . '/?page=file-view&file=' . rawurlencode($relPath);
    }
    $raw = @file_get_contents($abs);
    if ($raw === false || $raw === '') {
        return null;
    }
    $ext = strtolower(pathinfo($relPath, PATHINFO_EXTENSION));
    $mime = ($ext === 'jpg' || $ext === 'jpeg') ? 'image/jpeg' : 'image/png';
    return 'data:' . $mime . ';base64,' . base64_encode($raw);
}

/**
 * Bearer/driver specimen: user whose name matches driver_name, else requester.
 */
function gasVoucherDriverEsignSrc(object $voucher): ?string
{
    $driver = strtolower(preg_replace('/\s+/', ' ', trim((string) ($voucher->driver_name ?? ''))) ?? '');
    $reqName = strtolower(preg_replace('/\s+/', ' ', trim((string) ($voucher->requester_name ?? ''))) ?? '');
    $fromRequester = gasVoucherEsignSrc($voucher->requester_signature_path ?? null);

    if ($driver !== '' && function_exists('db')) {
        $row = db()->fetch(
            "SELECT signature_path FROM users
             WHERE deleted_at IS NULL AND status = 'active'
               AND LOWER(TRIM(name)) = ?
             LIMIT 1",
            [$driver]
        );
        $fromDriver = gasVoucherEsignSrc(is_object($row) ? ($row->signature_path ?? null) : null);
        if ($fromDriver !== null) {
            return $fromDriver;
        }
    }

    if ($fromRequester !== null && ($driver === '' || $driver === $reqName)) {
        return $fromRequester;
    }

    return $fromRequester;
}
