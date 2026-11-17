<?php
/**
 * Admin can replace a user's specimen file on disk.
 */
$base = sys_get_temp_dir() . '/user_esign_' . bin2hex(random_bytes(4));
mkdir($base . '/uploads/user_signatures', 0777, true);
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $base);
}
require_once dirname(__DIR__, 2) . '/public_html/includes/ob_esign.php';

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

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=');

expect('empty name', obStoreUserEsignFile(7, []), ['path' => null, 'error' => null]);
expect('blank name', obStoreUserEsignFile(7, ['name' => '']), ['path' => null, 'error' => null]);

$tmp = $base . '/in.png';
file_put_contents($tmp, $png);
$first = obStoreUserEsignFile(7, [
    'name' => 'sig.png',
    'type' => 'image/png',
    'tmp_name' => $tmp,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($tmp),
]);
expect('store path', $first['path'], 'uploads/user_signatures/7.png');
expect('store error', $first['error'], null);
expect('file exists', is_file(BASE_PATH . '/uploads/user_signatures/7.png'), true);

$tmp2 = $base . '/in2.png';
file_put_contents($tmp2, $png);
$second = obStoreUserEsignFile(7, [
    'name' => 'replace.png',
    'type' => 'image/png',
    'tmp_name' => $tmp2,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($tmp2),
]);
expect('replace path', $second['path'], 'uploads/user_signatures/7.png');
expect('replace still one png', is_file(BASE_PATH . '/uploads/user_signatures/7.png'), true);

$tooBig = obStoreUserEsignFile(8, [
    'name' => 'big.png',
    'type' => 'image/png',
    'tmp_name' => $tmp,
    'error' => UPLOAD_ERR_OK,
    'size' => USER_ESIGN_MAX_BYTES + 1,
]);
expect('reject oversize', $tooBig['path'], null);
expect('oversize message', is_string($tooBig['error']) && str_contains($tooBig['error'], '3 MB'), true);

if ($failed > 0) {
    fwrite(STDERR, "{$failed} failed\n");
    exit(1);
}
echo "all passed\n";
