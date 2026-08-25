<?php
/**
 * Image resize/compress helpers for lightweight observation photos.
 */

/**
 * Optimize an uploaded image into thumb + full JPEG variants.
 *
 * @return array{thumb_path: string, full_path: string, width: int, height: int, file_size: int, mime_type: string}|null
 */
function optimizeObservationImage(
    string $tmpPath,
    string $destRelativeDir,
    string $prefix = 'obs',
    int $fullMaxEdge = 1600,
    int $thumbMaxEdge = 480,
    int $jpegQuality = 82,
    int $maxFullBytes = 716800
): ?array {
    if (!is_file($tmpPath) || !function_exists('imagecreatefromstring')) {
        return null;
    }

    $raw = @file_get_contents($tmpPath);
    if ($raw === false || $raw === '') {
        return null;
    }

    $src = @imagecreatefromstring($raw);
    if (!$src) {
        return null;
    }

    // Autorotate from EXIF when available
    if (function_exists('exif_read_data')) {
        $exif = @exif_read_data($tmpPath);
        if (!empty($exif['Orientation'])) {
            $src = imageApplyExifOrientation($src, (int) $exif['Orientation']);
        }
    }

    $baseAbs = rtrim(BASE_PATH, '/\\') . DIRECTORY_SEPARATOR;
    $relDir = trim(str_replace(['\\', '..'], ['/', ''], $destRelativeDir), '/');
    if (!str_starts_with($relDir, 'uploads/')) {
        imagedestroy($src);
        return null;
    }

    $absDir = $baseAbs . str_replace('/', DIRECTORY_SEPARATOR, $relDir);
    if (!is_dir($absDir) && !mkdir($absDir, 0755, true)) {
        imagedestroy($src);
        return null;
    }

    $htaccess = $absDir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Order Deny,Allow\nDeny from all\n");
    }

    $token = $prefix . '_' . uniqid('', true) . '_' . bin2hex(random_bytes(3));
    $fullRel = $relDir . '/full_' . $token . '.jpg';
    $thumbRel = $relDir . '/thumb_' . $token . '.jpg';
    $fullAbs = $baseAbs . str_replace('/', DIRECTORY_SEPARATOR, $fullRel);
    $thumbAbs = $baseAbs . str_replace('/', DIRECTORY_SEPARATOR, $thumbRel);

    $full = imageResizeToMaxEdge($src, $fullMaxEdge);
    if (!$full) {
        imagedestroy($src);
        return null;
    }

    $quality = $jpegQuality;
    if (!imagejpeg($full, $fullAbs, $quality)) {
        imagedestroy($full);
        imagedestroy($src);
        return null;
    }

    // Re-encode smaller if still over budget
    $attempts = 0;
    while (filesize($fullAbs) > $maxFullBytes && $attempts < 4) {
        $attempts++;
        $quality = max(60, $quality - 5);
        $edge = (int) round(imagesx($full) * 0.85);
        $resized = imageResizeToMaxEdge($full, max(1280, $edge));
        if ($resized && $resized !== $full) {
            imagedestroy($full);
            $full = $resized;
        }
        imagejpeg($full, $fullAbs, $quality);
    }

    $thumb = imageResizeToMaxEdge($full, $thumbMaxEdge);
    if (!$thumb || !imagejpeg($thumb, $thumbAbs, 72)) {
        @unlink($fullAbs);
        if ($thumb) {
            imagedestroy($thumb);
        }
        imagedestroy($full);
        imagedestroy($src);
        return null;
    }

    $meta = [
        'thumb_path' => $thumbRel,
        'full_path' => $fullRel,
        'width' => imagesx($full),
        'height' => imagesy($full),
        'file_size' => (int) filesize($fullAbs),
        'mime_type' => 'image/jpeg',
    ];

    imagedestroy($thumb);
    imagedestroy($full);
    imagedestroy($src);
    @chmod($fullAbs, 0644);
    @chmod($thumbAbs, 0644);

    return $meta;
}

/**
 * @param \GdImage|resource $src
 * @return \GdImage|resource|null
 */
function imageResizeToMaxEdge($src, int $maxEdge)
{
    $w = imagesx($src);
    $h = imagesy($src);
    if ($w < 1 || $h < 1) {
        return null;
    }

    $scale = min(1.0, $maxEdge / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));

    if ($nw === $w && $nh === $h) {
        // Clone so caller can destroy independently
        $dst = imagecreatetruecolor($w, $h);
        imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
        return $dst;
    }

    $dst = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    return $dst;
}

/**
 * @param \GdImage|resource $img
 * @return \GdImage|resource
 */
function imageApplyExifOrientation($img, int $orientation)
{
    switch ($orientation) {
        case 3:
            $img = imagerotate($img, 180, 0);
            break;
        case 6:
            $img = imagerotate($img, -90, 0);
            break;
        case 8:
            $img = imagerotate($img, 90, 0);
            break;
    }
    return $img;
}
