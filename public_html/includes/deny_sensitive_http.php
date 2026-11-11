<?php
/**
 * Block leftover document-root junk when nginx falls through to PHP.
 * Static files that still exist under these paths can still be served by
 * root nginx; keep them off disk (see harden_logs.php and app_logs).
 */
if (PHP_SAPI === 'cli') {
    return;
}

$reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (
    preg_match('#^/(logs|vendor)(/|$)#i', $reqPath)
    || preg_match('#\.(log|sql|bak|env|sh|md)$#i', $reqPath)
) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    echo 'Forbidden';
    exit;
}
