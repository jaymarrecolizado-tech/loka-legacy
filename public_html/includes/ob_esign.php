<?php
/**
 * Plan #23 — saved staff e-sign specimen.
 * Loaded from ob_requests.php after obSaveSignature() exists.
 * Admin uploads a specimen onto the user record; approve / guard departure
 * copies it onto the slip. The CoA (receiving client) always signs fresh.
 */

if (defined('OB_ESIGN_LOADED')) {
    return;
}
define('OB_ESIGN_LOADED', 1);

/** The user's saved specimen path, or null when none/no file on disk. */
function obUserEsignPath(?int $userId): ?string
{
    if (!$userId) {
        return null;
    }
    $p = db()->fetchColumn("SELECT signature_path FROM users WHERE id = ?", [$userId]);
    return ($p !== null && $p !== '' && is_file(BASE_PATH . '/' . $p)) ? (string) $p : null;
}

/**
 * Copy the specimen onto this OB slip (uploads/ob_signatures/{id}/{who}.png)
 * and return the slip path. Copying (not referencing) means replacing the
 * specimen later never rewrites already-stamped slips.
 */
function obStampSavedEsign(int $obId, string $who, int $userId): ?string
{
    $specimen = obUserEsignPath($userId);
    if ($specimen === null || !in_array($who, OB_SIGNATURE_OWNERS, true)) {
        return null;
    }
    $dir = BASE_PATH . '/uploads/ob_signatures/' . $obId;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return null;
    }
    $srcAbs = BASE_PATH . '/' . $specimen;
    $slipPath = 'uploads/ob_signatures/' . $obId . '/' . $who . '.png';
    $destAbs = BASE_PATH . '/' . $slipPath;

    $info = @getimagesize($srcAbs);
    if (($info[2] ?? null) === IMAGETYPE_PNG) {
        if (!@copy($srcAbs, $destAbs)) {
            error_log("obStampSavedEsign: copy failed for ob #{$obId} ({$who})");
            return null;
        }
        return $slipPath;
    }

    // Admin may upload JPG; the Pass Slip print embeds type PNG.
    $raw = @file_get_contents($srcAbs);
    $img = ($raw !== false && $raw !== '') ? @imagecreatefromstring($raw) : false;
    if ($img === false) {
        error_log("obStampSavedEsign: cannot decode specimen for ob #{$obId} ({$who})");
        return null;
    }
    if (function_exists('imagepalettetotruecolor')) {
        @imagepalettetotruecolor($img);
    }
    imagesavealpha($img, true);
    $ok = imagepng($img, $destAbs, 6);
    imagedestroy($img);
    if (!$ok) {
        error_log("obStampSavedEsign: PNG write failed for ob #{$obId} ({$who})");
        return null;
    }
    return $slipPath;
}

/**
 * Resolve the staff signature for an approve/departure action:
 *  1. a canvas draw wins (fresh signature this time);
 *  2. otherwise the saved specimen is copied onto the slip;
 *  3. a canvas with "save for next time" also becomes the specimen when
 *     the user has none yet.
 *
 * @return array{path:?string,error:?string}
 */
function obResolveStaffSignature(int $obId, string $who, int $userId, string $canvasDataUrl, bool $saveForNextTime): array
{
    if ($canvasDataUrl !== '') {
        $slipPath = obSaveSignature($obId, $who, $canvasDataUrl);
        if ($slipPath === null) {
            return ['path' => null, 'error' => 'Could not save the canvas signature. Please draw it again.'];
        }

        if ($saveForNextTime && obUserEsignPath($userId) === null) {
            $dir = BASE_PATH . '/uploads/user_signatures';
            if (is_dir($dir) || mkdir($dir, 0775, true)) {
                $specimenRel = 'uploads/user_signatures/' . $userId . '.png';
                if (@copy(BASE_PATH . '/' . $slipPath, BASE_PATH . '/' . $specimenRel)) {
                    db()->update('users', ['signature_path' => $specimenRel, 'updated_at' => date(DATETIME_FORMAT)], 'id = ?', [$userId]);
                }
            }
        }
        return ['path' => $slipPath, 'error' => null];
    }

    $slipPath = obStampSavedEsign($obId, $who, $userId);
    if ($slipPath !== null) {
        return ['path' => $slipPath, 'error' => null];
    }

    return ['path' => null, 'error' => 'A signature is required — draw one on the canvas (you have no saved e-sign yet).'];
}

/**
 * Handle the admin specimen upload on User create/edit (multipart).
 * Accepts PNG/JPG up to ~1 MB; stores as uploads/user_signatures/{userId}.{ext}.
 * path null + error null = no file chosen.
 *
 * @return array{path:?string,error:?string}
 */
function obSaveUserEsignUpload(int $userId, array $file): array
{
    if (!isset($file['name']) || $file['name'] === '') {
        return ['path' => null, 'error' => null];
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['path' => null, 'error' => 'Specimen upload failed. Please try again.'];
    }
    if (($file['size'] ?? 0) > 1048576) {
        return ['path' => null, 'error' => 'Specimen must be 1 MB or smaller.'];
    }
    $info = @getimagesize($file['tmp_name'] ?? '');
    $ext = match ($info[2] ?? null) {
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_JPEG => 'jpg',
        default => null,
    };
    if ($ext === null) {
        return ['path' => null, 'error' => 'Specimen must be a PNG or JPG image.'];
    }

    $dir = BASE_PATH . '/uploads/user_signatures';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['path' => null, 'error' => 'Could not create the specimen storage folder.'];
    }

    foreach (['png', 'jpg'] as $old) {
        $oldPath = $dir . '/' . $userId . '.' . $old;
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    $rel = 'uploads/user_signatures/' . $userId . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], BASE_PATH . '/' . $rel)) {
        return ['path' => null, 'error' => 'Could not store the specimen file.'];
    }
    @chmod(BASE_PATH . '/' . $rel, 0644);

    db()->update('users', ['signature_path' => $rel, 'updated_at' => date(DATETIME_FORMAT)], 'id = ?', [$userId]);
    return ['path' => $rel, 'error' => null];
}

/** Remove a user's specimen (admin "clear"). */
function obClearUserEsign(int $userId): void
{
    foreach (['png', 'jpg'] as $ext) {
        $p = BASE_PATH . '/uploads/user_signatures/' . $userId . '.' . $ext;
        if (is_file($p)) {
            @unlink($p);
        }
    }
    db()->update('users', ['signature_path' => null, 'updated_at' => date(DATETIME_FORMAT)], 'id = ?', [$userId]);
}
