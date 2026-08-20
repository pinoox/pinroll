<?php

declare(strict_types=1);

/**
 * PinGate HTTP runtime — compiled into a single pingate.php next to index.php.
 * Platform root is __DIR__ of pingate.php (not a parent of a former gate/ folder).
 */
function pinroll_pingate_run(string $root, array $gateConfig = []): void
{
    @ini_set('memory_limit', '512M');
    @set_time_limit(600);

    $root = rtrim(str_replace('\\', '/', $root), '/');
    $configFile = $root . '/pingate.php';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = trim((string) ($_GET['route'] ?? ''), '/');

    if ($method === 'POST' && $path === 'vendor') {
        $input = json_decode((string) file_get_contents('php://input'), true);
        pinroll_handle_vendor_extract(
            $root,
            $gateConfig,
            is_array($input) ? $input : [],
            $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null),
        );

        return;
    }

    if ($method === 'POST' && $path === 'bootstrap') {
        $input = json_decode((string) file_get_contents('php://input'), true);
        pinroll_handle_platform_bootstrap(
            $root,
            $gateConfig,
            is_array($input) ? $input : [],
            $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null),
        );

        return;
    }

    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null);
    if (!pinroll_gate_try_auth($root, $gateConfig, $auth)) {
        return;
    }

    if ($method === 'GET' && $path === 'status') {
        pinroll_handle_light_status($root, $gateConfig);

        return;
    }

    if ($method === 'POST' && ($path === 'install' || $path === 'apply')) {
        $input = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($input) || $input === []) {
            $input = $_POST;
        }
        pinroll_handle_direct_install($root, $gateConfig, is_array($input) ? $input : []);

        return;
    }

    if ($method === 'POST' && $path === 'cleanup') {
        $input = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($input) || $input === []) {
            $input = $_POST;
        }
        pinroll_handle_direct_cleanup($root, $gateConfig, is_array($input) ? $input : []);

        return;
    }

    if ($method === 'POST' && str_starts_with($path, 'push/')) {
        pinroll_handle_direct_push($root, $gateConfig, $path);

        return;
    }

    try {
        $root = pinroll_resolve_platform_root($root, $gateConfig);
    } catch (Throwable $e) {
        pinroll_gate_json_error(503, $e->getMessage());

        return;
    }

    if (!defined('PINOOX_BASE_PATH')) {
        define('PINOOX_BASE_PATH', $root);
    }

    pinroll_load_platform_autoload($root);

    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input) || $input === []) {
        $input = $_POST;
    }
    if (!is_array($input)) {
        $input = [];
    }
    if (isset($_FILES['chunk']['tmp_name']) && is_string($_FILES['chunk']['tmp_name'])) {
        $input['chunk'] = $_FILES['chunk']['tmp_name'];
    }

    try {
        if (class_exists(\Pinoox\Pinroll\Pinroll::class, true)) {
            \Pinoox\Pinroll\Pinroll::configure([], new \Pinoox\Pinroll\Support\NativePathResolver($root));
            $result = \Pinoox\Pinroll\Pinroll::gate()->handle($method, $path, $input, $auth, $configFile);
        } else {
            $result = pinroll_pincore_gate_handle($root, $method, $path, $input, $auth, $gateConfig);
        }
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        pinroll_gate_json_error((int) ($e->getCode() ?: 500), $e->getMessage());
    }
}

function pinroll_load_platform_autoload(string $root): void
{
    $autoload = rtrim($root, '/') . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;

        return;
    }

    pinroll_gate_json_error(
        503,
        'Platform not found. Install Pinoox on this host first (missing vendor/autoload.php).',
    );
    exit;
}

/**
 * @param array<string, mixed> $gateConfig
 */
function pinroll_resolve_platform_root(string $startDir, array $gateConfig = []): string
{
    $startDir = rtrim(str_replace('\\', '/', $startDir), '/');
    $configured = trim(str_replace('\\', '/', (string) ($gateConfig['platform_root'] ?? '')));

    if ($configured !== '' && $configured !== '.' && $configured !== './') {
        $resolved = pinroll_absolute_platform_root($configured, $startDir);
        if ($resolved !== null) {
            return $resolved;
        }
    }

    if (basename($startDir) !== 'gate' && pinroll_looks_like_platform_root($startDir)) {
        return $startDir;
    }

    $current = $startDir;
    for ($depth = 0; $depth < 8; $depth++) {
        $next = dirname($current);
        if ($next === $current) {
            break;
        }
        $current = $next;
        if (basename($current) === 'gate') {
            continue;
        }
        if (pinroll_looks_like_platform_root($current)) {
            return $current;
        }
    }

    throw new RuntimeException(
        'Pinoox platform root not found. Install Pinoox next to pingate.php (same folder as index.php).',
    );
}

function pinroll_looks_like_platform_root(string $dir): bool
{
    $dir = rtrim(str_replace('\\', '/', $dir), '/');

    return is_file($dir . '/vendor/autoload.php')
        || is_file($dir . '/index.php')
        || is_file($dir . '/pinoox');
}

function pinroll_absolute_platform_root(string $configured, string $startDir): ?string
{
    $isAbsolute = str_starts_with($configured, '/')
        || preg_match('#^[a-zA-Z]:[\\\\/]#', $configured) === 1;

    if (!$isAbsolute) {
        $candidate = rtrim(str_replace('\\', '/', $startDir . '/' . $configured), '/');
        $real = realpath($candidate);
        $candidate = is_string($real) ? $real : $candidate;
    } else {
        $candidate = rtrim(str_replace('\\', '/', $configured), '/');
        $real = realpath($candidate);
        $candidate = is_string($real) ? str_replace('\\', '/', $real) : $candidate;
    }

    return pinroll_looks_like_platform_root($candidate) ? $candidate : null;
}

function pinroll_gate_json_error(int $status, string $message): void
{
    http_response_code($status > 0 ? $status : 500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
}

/**
 * @param array<string, mixed> $gateConfig
 * @param array<string, mixed> $input
 */
function pinroll_handle_vendor_extract(string $root, array $gateConfig, array $input, ?string $authorization): void
{
    $rootReal = realpath($root);
    if ($rootReal === false || !is_dir($rootReal)) {
        pinroll_gate_json_error(500, 'Invalid platform root.');

        return;
    }
    $root = str_replace('\\', '/', $rootReal);

    $expectedHash = pinroll_gate_token_hash($root, $gateConfig);
    if ($expectedHash === '' || !preg_match('/^[a-f0-9]{64}$/', $expectedHash)) {
        pinroll_gate_json_error(503, 'PinGate token_hash missing or invalid. Re-run pinroll:gate.');

        return;
    }

    if (pinroll_vendor_auth_blocked($root)) {
        pinroll_gate_json_error(429, 'Too many failed attempts. Try again later.');

        return;
    }

    $token = pinroll_extract_bearer($authorization);
    if ($token === '' || !hash_equals($expectedHash, hash('sha256', $token))) {
        pinroll_vendor_auth_failure($root);
        pinroll_gate_json_error(401, 'Invalid token.');

        return;
    }

    pinroll_vendor_auth_reset($root);

    if (!class_exists(ZipArchive::class)) {
        pinroll_gate_json_error(500, 'ZipArchive is not available on the host PHP.');

        return;
    }

    // Never trust client-provided zip names — only vendor.zip next to pingate.php.
    $zipPath = $root . '/vendor.zip';
    if (!is_file($zipPath) || !is_readable($zipPath)) {
        pinroll_gate_json_error(404, 'vendor.zip not found next to pingate.php.');

        return;
    }

    $zipReal = realpath($zipPath);
    if ($zipReal === false || !str_starts_with(str_replace('\\', '/', $zipReal), $root . '/')) {
        pinroll_gate_json_error(400, 'Refusing zip outside platform root.');

        return;
    }

    $maxZipBytes = 200 * 1024 * 1024; // 200 MB compressed
    $zipSize = (int) filesize($zipReal);
    if ($zipSize < 1 || $zipSize > $maxZipBytes) {
        pinroll_gate_json_error(400, 'vendor.zip size is invalid or too large.');

        return;
    }

    $vendorDir = $root . '/vendor';
    $tmp = $root . '/storage/tmp';
    if (!is_dir($tmp)) {
        @mkdir($tmp, 0755, true);
    }
    $backupDir = $tmp . '/vendor-bak-' . bin2hex(random_bytes(4));

    if (is_dir($vendorDir)) {
        if (!@rename($vendorDir, $backupDir)) {
            pinroll_gate_json_error(500, 'Unable to move existing vendor/ aside before extract.');

            return;
        }
    }

    $zip = new ZipArchive();
    if ($zip->open($zipReal) !== true) {
        if (is_dir($backupDir)) {
            @rename($backupDir, $vendorDir);
        }
        pinroll_gate_json_error(500, 'Unable to open vendor.zip.');

        return;
    }

    $inspect = pinroll_vendor_zip_is_safe($zip, $root);
    if ($inspect !== true) {
        $zip->close();
        if (is_dir($backupDir)) {
            @rename($backupDir, $vendorDir);
        }
        pinroll_gate_json_error(400, is_string($inspect) ? $inspect : 'Unsafe vendor.zip rejected.');

        return;
    }

    $ok = pinroll_vendor_zip_extract_safe($zip, $root);
    $zip->close();

    if ($ok !== true || !is_file($vendorDir . '/autoload.php')) {
        if (is_dir($vendorDir)) {
            pinroll_remove_directory($vendorDir);
        }
        if (is_dir($backupDir)) {
            @rename($backupDir, $vendorDir);
        }
        pinroll_gate_json_error(500, is_string($ok) ? $ok : 'Vendor extract failed or autoload.php missing.');

        return;
    }

    if (is_dir($backupDir)) {
        pinroll_remove_directory($backupDir);
    }

    $deletedZip = @unlink($zipReal);

    pinroll_refresh_pinker_overrides($root);

    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    echo json_encode([
        'success' => true,
        'data' => [
            'vendor' => 'vendor',
            'zip' => 'vendor.zip',
            'deleted_zip' => (bool) $deletedZip,
            'autoload' => true,
        ],
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Extract platform.zip next to pingate.php (first-time install). Never trusts client zip names.
 *
 * @param array<string, mixed> $gateConfig
 * @param array<string, mixed> $input
 */
function pinroll_handle_platform_bootstrap(string $root, array $gateConfig, array $input, ?string $authorization): void
{
    $rootReal = realpath($root);
    if ($rootReal === false || !is_dir($rootReal)) {
        pinroll_gate_json_error(500, 'Invalid platform root.');

        return;
    }
    $root = str_replace('\\', '/', $rootReal);

    $expectedHash = pinroll_gate_token_hash($root, $gateConfig);
    if ($expectedHash === '' || !preg_match('/^[a-f0-9]{64}$/', $expectedHash)) {
        pinroll_gate_json_error(503, 'PinGate token_hash missing or invalid. Re-run pinroll:gate.');

        return;
    }

    if (pinroll_vendor_auth_blocked($root)) {
        pinroll_gate_json_error(429, 'Too many failed attempts. Try again later.');

        return;
    }

    $token = pinroll_extract_bearer($authorization);
    if ($token === '' || !hash_equals($expectedHash, hash('sha256', $token))) {
        pinroll_vendor_auth_failure($root);
        pinroll_gate_json_error(401, 'Invalid token.');

        return;
    }

    pinroll_vendor_auth_reset($root);

    $force = !empty($input['force']);
    if (!$force && is_file($root . '/index.php')) {
        pinroll_gate_json_error(409, 'Platform already present (index.php). Pass force=true to extract again.');

        return;
    }

    if (!class_exists(ZipArchive::class)) {
        pinroll_gate_json_error(500, 'ZipArchive is not available on the host PHP.');

        return;
    }

    $zipPath = $root . '/platform.zip';
    if (!is_file($zipPath) || !is_readable($zipPath)) {
        pinroll_gate_json_error(404, 'platform.zip not found next to pingate.php.');

        return;
    }

    $zipReal = realpath($zipPath);
    if ($zipReal === false || !str_starts_with(str_replace('\\', '/', $zipReal), $root . '/')) {
        pinroll_gate_json_error(400, 'Refusing zip outside platform root.');

        return;
    }

    $maxZipBytes = 400 * 1024 * 1024;
    $zipSize = (int) filesize($zipReal);
    if ($zipSize < 1 || $zipSize > $maxZipBytes) {
        pinroll_gate_json_error(400, 'platform.zip size is invalid or too large.');

        return;
    }

    $zip = new ZipArchive();
    if ($zip->open($zipReal) !== true) {
        pinroll_gate_json_error(500, 'Unable to open platform.zip.');

        return;
    }

    $inspect = pinroll_platform_zip_is_safe($zip, $root);
    if ($inspect !== true) {
        $zip->close();
        pinroll_gate_json_error(400, is_string($inspect) ? $inspect : 'Unsafe platform.zip rejected.');

        return;
    }

    $ok = pinroll_platform_zip_extract_safe($zip, $root);
    $zip->close();

    if ($ok !== true || !is_file($root . '/index.php') || !is_file($root . '/vendor/autoload.php')) {
        pinroll_gate_json_error(500, is_string($ok) ? $ok : 'Platform extract failed or index.php / vendor/autoload.php missing.');

        return;
    }

    $deletedZip = @unlink($zipReal);
    pinroll_refresh_pinker_overrides($root);

    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    echo json_encode([
        'success' => true,
        'data' => [
            'platform' => true,
            'zip' => 'platform.zip',
            'deleted_zip' => (bool) $deletedZip,
            'index' => true,
            'autoload' => true,
        ],
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * @return true|string
 */
function pinroll_platform_zip_is_safe(ZipArchive $zip, string $root)
{
    $maxFiles = 80000;
    $maxUncompressed = 1500 * 1024 * 1024;
    $count = $zip->numFiles;

    if ($count < 1 || $count > $maxFiles) {
        return 'platform.zip has an invalid entry count.';
    }

    $names = [];
    $totalUncompressed = 0;

    for ($i = 0; $i < $count; $i++) {
        $stat = $zip->statIndex($i);
        if (!is_array($stat)) {
            return 'Unable to read zip entry metadata.';
        }

        $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
        if ($name === '' || str_contains($name, "\0")) {
            return 'Zip entry name is invalid.';
        }

        if ($name[0] === '/' || preg_match('#^[a-zA-Z]:#', $name) === 1 || str_contains($name, '..')) {
            return 'Zip entry path traversal rejected: ' . $name;
        }

        $attrs = (int) ($stat['external_attr'] ?? 0);
        $type = ($attrs >> 16) & 0xF000;
        if ($type === 0xA000) {
            return 'Symlink entries are not allowed in platform.zip.';
        }

        $size = (int) ($stat['size'] ?? 0);
        if ($size < 0) {
            return 'Invalid uncompressed size in zip.';
        }

        $totalUncompressed += $size;
        if ($totalUncompressed > $maxUncompressed) {
            return 'platform.zip uncompressed size exceeds limit.';
        }

        $names[] = $name;
    }

    $prefix = pinroll_platform_zip_prefix($names);
    $hasIndex = false;
    $hasAutoload = false;
    foreach ($names as $name) {
        $relative = $prefix === '' ? $name : (str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : $name);
        $relative = ltrim(str_replace('\\', '/', (string) $relative), '/');
        if ($relative === 'index.php') {
            $hasIndex = true;
        }
        if ($relative === 'vendor/autoload.php') {
            $hasAutoload = true;
        }
    }

    unset($root);

    if (!$hasIndex || !$hasAutoload) {
        return 'platform.zip must include index.php and vendor/autoload.php.';
    }

    return true;
}

/**
 * @param list<string> $entries
 */
function pinroll_platform_zip_prefix(array $entries): string
{
    $files = [];
    foreach ($entries as $entry) {
        $entry = ltrim(str_replace('\\', '/', $entry), '/');
        if ($entry === '' || str_ends_with($entry, '/')) {
            continue;
        }
        $files[] = $entry;
    }

    if ($files === []) {
        return '';
    }

    if (in_array('index.php', $files, true) || in_array('storage/BUILD.json', $files, true)) {
        return '';
    }

    $slash = strpos($files[0], '/');
    if ($slash === false) {
        return '';
    }

    $root = substr($files[0], 0, $slash + 1);
    foreach ($files as $file) {
        if (!str_starts_with($file, $root)) {
            return '';
        }
    }

    if (!in_array($root . 'index.php', $files, true) && !in_array($root . 'storage/BUILD.json', $files, true)) {
        return '';
    }

    return $root;
}

function pinroll_platform_should_preserve(string $relative): bool
{
    $relative = ltrim(str_replace('\\', '/', $relative), '/');
    if ($relative === '' || $relative === 'storage/BUILD.json') {
        return false;
    }

    if ($relative === '.env' || str_starts_with($relative, '.env.')) {
        return true;
    }

    $prefixes = [
        'storage',
        'uploads',
        'downloads',
        'pinker',
        'pinx',
        'pinroll',
        '.pinoox',
        'packages',
        'pincore',
        'pingate.php',
        '.git',
        '.github',
        'platform/app-router.config.php',
        'platform/domain.config.php',
        'platform/apps.config.php',
    ];

    foreach ($prefixes as $prefix) {
        if ($relative === $prefix || str_starts_with($relative, $prefix . '/')) {
            return true;
        }
    }

    return false;
}

/**
 * @return true|string
 */
function pinroll_platform_zip_extract_safe(ZipArchive $zip, string $root)
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $count = $zip->numFiles;
    $names = [];
    for ($i = 0; $i < $count; $i++) {
        $name = $zip->getNameIndex($i);
        if (is_string($name) && $name !== '') {
            $names[] = str_replace('\\', '/', $name);
        }
    }
    $prefix = pinroll_platform_zip_prefix($names);

    for ($i = 0; $i < $count; $i++) {
        $stat = $zip->statIndex($i);
        if (!is_array($stat)) {
            return 'Unable to read zip entry during extract.';
        }

        $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
        $relative = $prefix === '' ? $name : (str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : $name);
        $relative = ltrim(str_replace('\\', '/', (string) $relative), '/');

        if ($relative === '' || pinroll_platform_should_preserve($relative)) {
            continue;
        }

        if (str_ends_with($relative, '/')) {
            $dir = $root . '/' . rtrim($relative, '/');
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                return 'Unable to create directory for zip entry.';
            }

            continue;
        }

        $target = $root . '/' . $relative;
        $normalizedTarget = str_replace('\\', '/', $target);
        if ($normalizedTarget !== $root && !str_starts_with($normalizedTarget, $root . '/')) {
            return 'Refusing extract outside platform root: ' . $relative;
        }

        $targetDir = dirname($target);
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            return 'Unable to create parent directory for zip entry.';
        }

        $stream = $zip->getStream($name);
        if ($stream === false) {
            return 'Unable to read zip stream for ' . $name;
        }

        $out = @fopen($target, 'wb');
        if ($out === false) {
            fclose($stream);

            return 'Unable to write extracted file.';
        }

        stream_copy_to_stream($stream, $out);
        fclose($stream);
        fclose($out);
    }

    return true;
}

function pinroll_extract_bearer(?string $authorization): string
{
    if (!is_string($authorization) || $authorization === '') {
        $authorization = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    }

    if (!is_string($authorization) || !str_starts_with($authorization, 'Bearer ')) {
        return '';
    }

    $token = trim(substr($authorization, 7));
    // Expect 64 hex chars (TokenGenerator::token).
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return '';
    }

    return $token;
}

function pinroll_vendor_rate_file(string $root): string
{
    $dir = rtrim($root, '/') . '/storage/pinroll/gate';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $safe = preg_replace('/[^a-zA-Z0-9_.:-]/', '_', $ip) ?: 'unknown';

    return rtrim(str_replace('\\', '/', $dir), '/') . '/vendor-auth-' . $safe . '.json';
}

function pinroll_vendor_auth_blocked(string $root): bool
{
    $file = pinroll_vendor_rate_file($root);
    if (!is_file($file)) {
        return false;
    }

    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        return false;
    }

    $blockedUntil = (int) ($data['blocked_until'] ?? 0);

    return $blockedUntil > time();
}

function pinroll_vendor_auth_failure(string $root): void
{
    $file = pinroll_vendor_rate_file($root);
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (!is_array($data)) {
        $data = ['count' => 0, 'blocked_until' => 0];
    }

    $data['count'] = (int) ($data['count'] ?? 0) + 1;
    if ($data['count'] >= 5) {
        $data['blocked_until'] = time() + 3600;
        $data['count'] = 0;
    }

    @file_put_contents($file, json_encode($data), LOCK_EX);
}

function pinroll_vendor_auth_reset(string $root): void
{
    $file = pinroll_vendor_rate_file($root);
    if (is_file($file)) {
        @unlink($file);
    }
}

/**
 * @return true|string
 */
function pinroll_vendor_zip_is_safe(ZipArchive $zip, string $root)
{
    $maxFiles = 50000;
    $maxUncompressed = 400 * 1024 * 1024; // 400 MB
    $count = $zip->numFiles;

    if ($count < 1 || $count > $maxFiles) {
        return 'vendor.zip has an invalid entry count.';
    }

    $totalUncompressed = 0;
    $hasAutoload = false;

    for ($i = 0; $i < $count; $i++) {
        $stat = $zip->statIndex($i);
        if (!is_array($stat)) {
            return 'Unable to read zip entry metadata.';
        }

        $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
        if ($name === '' || str_contains($name, "\0")) {
            return 'Zip entry name is invalid.';
        }

        // Zip slip / absolute paths / Windows drives
        if ($name[0] === '/' || preg_match('#^[a-zA-Z]:#', $name) === 1 || str_contains($name, '..')) {
            return 'Zip entry path traversal rejected: ' . $name;
        }

        // Only allow vendor/ tree (and optional trailing slash dirs under it).
        if ($name !== 'vendor/' && !str_starts_with($name, 'vendor/')) {
            return 'Zip may only contain vendor/ paths. Rejected: ' . $name;
        }

        // Reject symlink / special types when detectable (Unix external attrs).
        $attrs = (int) ($stat['external_attr'] ?? 0);
        $type = ($attrs >> 16) & 0xF000;
        if ($type === 0xA000) { // S_IFLNK
            return 'Symlink entries are not allowed in vendor.zip.';
        }

        $size = (int) ($stat['size'] ?? 0);
        if ($size < 0) {
            return 'Invalid uncompressed size in zip.';
        }

        $totalUncompressed += $size;
        if ($totalUncompressed > $maxUncompressed) {
            return 'vendor.zip uncompressed size exceeds limit.';
        }

        if ($name === 'vendor/autoload.php') {
            $hasAutoload = true;
        }
    }

    if (!$hasAutoload) {
        return 'vendor.zip must include vendor/autoload.php.';
    }

    unset($root);

    return true;
}

/**
 * Extract only safe vendor/ entries into $root.
 *
 * @return true|string
 */
function pinroll_vendor_zip_extract_safe(ZipArchive $zip, string $root)
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $count = $zip->numFiles;

    for ($i = 0; $i < $count; $i++) {
        $stat = $zip->statIndex($i);
        if (!is_array($stat)) {
            return 'Unable to read zip entry during extract.';
        }

        $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
        if ($name === '' || str_ends_with($name, '/')) {
            $dir = $root . '/' . rtrim($name, '/');
            if ($name !== '' && !is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                return 'Unable to create directory for zip entry.';
            }

            continue;
        }

        $target = $root . '/' . $name;
        $targetDir = dirname($target);
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            return 'Unable to create parent directory for zip entry.';
        }

        // Final path must stay under root/vendor
        $vendorPrefix = $root . '/vendor';
        $normalizedTarget = str_replace('\\', '/', $target);
        if ($normalizedTarget !== $vendorPrefix && !str_starts_with($normalizedTarget, $vendorPrefix . '/')) {
            return 'Refusing extract outside vendor/: ' . $name;
        }

        $stream = $zip->getStream($name);
        if ($stream === false) {
            return 'Unable to read zip stream for ' . $name;
        }

        $out = @fopen($target, 'wb');
        if ($out === false) {
            fclose($stream);

            return 'Unable to write extracted file.';
        }

        stream_copy_to_stream($stream, $out);
        fclose($stream);
        fclose($out);
    }

    return true;
}

function pinroll_remove_directory(string $path): void
{
    $real = realpath($path);
    if ($real === false || !is_dir($real)) {
        return;
    }

    $normalized = str_replace('\\', '/', $real);
    if (!pinroll_remove_directory_allowed($normalized)) {
        return;
    }

    pinroll_remove_directory_contents($real);
}

function pinroll_remove_directory_allowed(string $normalized): bool
{
    return str_contains($normalized, '/vendor')
        || str_contains($normalized, '/storage/tmp')
        || str_contains($normalized, '/storage/pinion')
        || str_contains($normalized, '/storage/pinroll')
        || str_contains(basename($normalized), 'vendor-bak')
        || str_contains(basename($normalized), 'pinroll-vendor-bak')
        || preg_match('#/pinroll$#', $normalized) === 1;
}

function pinroll_remove_directory_contents(string $real): void
{
    $items = scandir($real);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $full = $real . DIRECTORY_SEPARATOR . $item;
        if (is_link($full)) {
            @unlink($full);
            continue;
        }

        if (is_dir($full)) {
            pinroll_remove_directory_contents($full);
            continue;
        }

        @unlink($full);
    }

    @rmdir($real);
}

function pinroll_gate_token_hash(string $root, array $gateConfig): string
{
    $envToken = pinroll_read_env_value($root, 'PINROLL_GATE_TOKEN');
    if ($envToken !== '' && preg_match('/^[a-f0-9]{64}$/', $envToken)) {
        return hash('sha256', $envToken);
    }

    return (string) ($gateConfig['token_hash'] ?? '');
}

function pinroll_read_env_value(string $root, string $key): string
{
    $fromEnv = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if (is_string($fromEnv) && $fromEnv !== '') {
        return trim($fromEnv, " \t\"'");
    }

    $file = rtrim($root, '/') . '/.env';
    if (!is_file($file)) {
        return '';
    }

    $pattern = '/^' . preg_quote($key, '/') . '\s*=\s*(.*)$/';
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim((string) $line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (preg_match($pattern, $line, $matches) !== 1) {
            continue;
        }

        return trim(trim((string) $matches[1]), "\"'");
    }

    return '';
}

function pinroll_refresh_pinker_overrides(string $root): void
{
    $autoload = rtrim($root, '/') . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }

    if (class_exists(\Pinoox\Component\Package\Pinx\PlatformPinkerGuard::class)) {
        \Pinoox\Component\Package\Pinx\PlatformPinkerGuard::refreshOverrideTimestamps($root);
    }
}

function pinroll_incoming_dir(string $root): string
{
    $dir = rtrim($root, '/') . '/storage/pinroll/incoming';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    return $dir;
}

function pinroll_assert_gate_auth(string $root, array $gateConfig, ?string $authorization): void
{
    $hash = pinroll_gate_token_hash($root, $gateConfig);
    if ($hash === '' || !preg_match('/^[a-f0-9]{64}$/', $hash)) {
        throw new RuntimeException('PinGate token_hash missing or invalid. Re-run pinroll:gate.', 503);
    }

    $token = pinroll_extract_bearer($authorization);
    if ($token === '') {
        throw new RuntimeException('Missing bearer token.', 401);
    }

    if (!hash_equals($hash, hash('sha256', $token))) {
        throw new RuntimeException('Invalid token.', 401);
    }
}

function pinroll_gate_try_auth(string $root, array $gateConfig, ?string $authorization): bool
{
    try {
        pinroll_assert_gate_auth($root, $gateConfig, $authorization);
    } catch (RuntimeException $e) {
        pinroll_gate_json_error((int) ($e->getCode() ?: 401), $e->getMessage());

        return false;
    }

    return true;
}

function pinroll_handle_light_status(string $root, array $gateConfig): void
{
    $platform = ['ok' => false, 'message' => 'Platform not checked'];

    try {
        $resolved = pinroll_resolve_platform_root($root, $gateConfig);
        $autoload = rtrim($resolved, '/') . '/vendor/autoload.php';
        if (is_file($autoload)) {
            $platform = ['ok' => true, 'message' => 'Pinx ready'];
        } else {
            $platform = ['ok' => false, 'message' => 'Platform not found. Install Pinoox on this host first (missing vendor/autoload.php).'];
        }
    } catch (Throwable $e) {
        $platform = ['ok' => false, 'message' => $e->getMessage()];
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'data' => [
            'status' => 'unknown',
            'platform' => $platform,
        ],
    ], JSON_UNESCAPED_UNICODE);
}

function pinroll_handle_direct_install(string $root, array $gateConfig, array $input): void
{
    try {
        $root = pinroll_resolve_platform_root($root, $gateConfig);
    } catch (Throwable $e) {
        pinroll_gate_json_error(503, $e->getMessage());

        return;
    }

    pinroll_load_platform_autoload($root);

    try {
        pinroll_boot_platform_for_setup($root);
        $incoming = pinroll_incoming_dir($root);
        $result = pinroll_pincore_install($root, $incoming, $input);
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        pinroll_gate_json_error((int) ($e->getCode() ?: 500), $e->getMessage());
    }
}

function pinroll_handle_direct_cleanup(string $root, array $gateConfig, array $input): void
{
    try {
        $root = pinroll_resolve_platform_root($root, $gateConfig);
    } catch (Throwable $e) {
        pinroll_gate_json_error(503, $e->getMessage());

        return;
    }

    $incoming = pinroll_incoming_dir($root);
    $result = pinroll_pincore_cleanup($root, $incoming, $input);
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
}

function pinroll_handle_direct_push(string $root, array $gateConfig, string $path): void
{
    try {
        $root = pinroll_resolve_platform_root($root, $gateConfig);
    } catch (Throwable $e) {
        pinroll_gate_json_error(503, $e->getMessage());

        return;
    }

    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input) || $input === []) {
        $input = $_POST;
    }
    if (!is_array($input)) {
        $input = [];
    }
    if (isset($_FILES['chunk']['tmp_name']) && is_string($_FILES['chunk']['tmp_name'])) {
        $input['chunk'] = $_FILES['chunk']['tmp_name'];
    }

    pinroll_load_platform_autoload($root);

    try {
        $incoming = pinroll_incoming_dir($root);
        $result = pinroll_pincore_push($root, $incoming, $path, $input);
        if (($result['success'] ?? true) === false) {
            $error = $result['error'] ?? 'Pinion error';
            $message = is_array($error) ? (string) ($error['message'] ?? json_encode($error)) : (string) $error;
            pinroll_gate_json_error((int) ($result['status'] ?? 400), $message);

            return;
        }
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        pinroll_gate_json_error((int) ($e->getCode() ?: 500), $e->getMessage());
    }
}

/**
 * Host handler when pinoox/pinroll is not installed (require-dev). Uses pincore Pinx + Pinion.
 *
 * @param array<string, mixed> $input
 * @param array<string, mixed> $gateConfig
 * @return array<string, mixed>
 */
function pinroll_pincore_gate_handle(
    string $root,
    string $method,
    string $path,
    array $input,
    ?string $authorization,
    array $gateConfig,
): array {
    pinroll_assert_gate_auth($root, $gateConfig, $authorization);
    $path = trim($path, '/');
    $incoming = pinroll_incoming_dir($root);

    if (!class_exists(\Pinoox\Pinion\Pinion::class)) {
        throw new RuntimeException('Pinion is not available on the host. Restore vendor/ first (POST ?route=vendor).');
    }

    $pinion = pinroll_pinion_http($root);

    return match (true) {
        $method === 'POST' && $path === 'push/init' => $pinion->init($input),
        $method === 'POST' && $path === 'push/upload' => $pinion->upload($input, $input['chunk'] ?? null),
        $method === 'POST' && $path === 'push/complete' => $pinion->complete($input),
        $method === 'POST' && ($path === 'install' || $path === 'apply') => pinroll_pincore_install($root, $incoming, $input),
        $method === 'GET' && $path === 'status' => ['status' => 'ready', 'platform' => ['ok' => true, 'message' => 'Pinx ready']],
        $method === 'GET' && $path === 'incoming' => pinroll_pincore_incoming($incoming),
        $method === 'POST' && $path === 'rollback' => pinroll_pincore_install($root, $incoming, $input + ['force' => true]),
        $method === 'POST' && $path === 'cleanup' => pinroll_pincore_cleanup($root, $incoming, $input),
        $method === 'GET' && $path === 'history' => ['history' => []],
        $method === 'POST' && $path === 'setup' => pinroll_pincore_setup($root, $input),
        $method === 'POST' && $path === 'check-db' => pinroll_pincore_check_db($root, $input),
        default => throw new RuntimeException('Unknown PinGate route: ' . $path),
    };
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function pinroll_pinion_http(string $root): \Pinoox\Pinion\HttpHandler
{
    if (!class_exists(\Pinoox\Pinion\Pinion::class)) {
        throw new RuntimeException('Pinion is not available on the host. Restore vendor/ first (POST ?route=vendor).');
    }

    $root = rtrim(str_replace('\\', '/', $root), '/');

    \Pinoox\Pinion\Pinion::configure(
        ['storage_path' => $root . '/storage/pinion'],
        new \Pinoox\Pinion\Support\NativePathResolver($root),
    );

    return \Pinoox\Pinion\Pinion::http(['destination' => 'storage/pinroll/incoming']);
}

function pinroll_pincore_push(string $root, string $incoming, string $path, array $input): array
{
    unset($incoming);
    $pinion = pinroll_pinion_http($root);
    $path = trim($path, '/');

    return match ($path) {
        'push/init' => $pinion->init($input),
        'push/upload' => $pinion->upload($input, $input['chunk'] ?? null),
        'push/complete' => $pinion->complete($input),
        default => throw new RuntimeException('Unknown PinGate route: ' . $path),
    };
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function pinroll_pincore_install(string $root, string $incoming, array $input): array
{
    $deployId = (string) ($input['deploy_id'] ?? '');
    $archive = pinroll_pincore_resolve_archive($incoming, $deployId !== '' ? $deployId : null);
    $resolvedId = preg_replace('/\.(tar|pinx|pin|zip)$/i', '', basename($archive)) ?: basename($archive);
    $workDir = $root . '/storage/tmp/apply/' . $resolvedId;
    $installable = $archive;
    $lower = strtolower($archive);
    if (str_ends_with($lower, '.tar')) {
        $installable = pinroll_pincore_extract_tar($archive, $workDir);
    }

    $force = !empty($input['force']);
    $ok = pinroll_pincore_apply_archive($installable, $force);
    pinroll_remove_directory($workDir);

    if (!$ok) {
        throw new RuntimeException('Package install failed.');
    }

    pinroll_refresh_pinker_overrides($root);

    return ['deploy_id' => $resolvedId, 'status' => 'applied'];
}

function pinroll_pincore_apply_archive(string $archive, bool $force): bool
{
    $isPlatform = class_exists(\Pinoox\Component\Package\Pinx\PlatformArchive::class)
        && \Pinoox\Component\Package\Pinx\PlatformArchive::isPlatformArchive($archive);

    if ($isPlatform && class_exists(\Pinoox\Portal\Pinx::class)) {
        $result = \Pinoox\Portal\Pinx::platformUpdater()->update($archive, ['force' => $force]);

        if (!($result->success ?? false)) {
            $message = trim((string) ($result->message ?? $result->error ?? ''));
            throw new RuntimeException($message !== '' ? $message : 'Platform update failed.');
        }

        return true;
    }

    if (!class_exists(\Pinoox\Portal\Pinx::class)) {
        throw new RuntimeException('Pincore Pinx is not available on the host.');
    }

    $result = \Pinoox\Portal\Pinx::installer()->install($archive, ['force' => $force]);

    if (!($result->success ?? false)) {
        $message = trim((string) ($result->message ?? $result->error ?? ''));
        throw new RuntimeException($message !== '' ? $message : 'Package install failed.');
    }

    return true;
}

function pinroll_pincore_resolve_archive(string $incoming, ?string $deployId): string
{
    if (!is_dir($incoming)) {
        throw new RuntimeException('No release archive found.');
    }

    $files = [];
    foreach (scandir($incoming) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $incoming . '/' . $name;
        if (!is_file($path)) {
            continue;
        }
        $lower = strtolower($name);
        if (!str_ends_with($lower, '.pinx') && !str_ends_with($lower, '.pin') && !str_ends_with($lower, '.tar') && !str_ends_with($lower, '.zip')) {
            continue;
        }
        $files[] = ['path' => $path, 'mtime' => (int) filemtime($path), 'id' => preg_replace('/\.(tar|pinx|pin|zip)$/i', '', $name) ?: $name];
    }

    usort($files, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
    if ($files === []) {
        throw new RuntimeException('No release archive found.');
    }

    if ($deployId === null || $deployId === '') {
        return $files[0]['path'];
    }

    foreach ($files as $file) {
        if ($file['id'] === $deployId || str_contains($file['path'], $deployId)) {
            return $file['path'];
        }
    }

    throw new RuntimeException('Release not found: ' . $deployId);
}

function pinroll_pincore_extract_tar(string $tarPath, string $workDir): string
{
    if (!class_exists(PharData::class)) {
        throw new RuntimeException('Phar extension is required to extract release archives.');
    }
    if (!is_dir($workDir)) {
        mkdir($workDir, 0755, true);
    }

    $phar = new PharData($tarPath);
    $phar->extractTo($workDir, null, true);
    $matches = glob($workDir . '/*.pinx') ?: [];
    if ($matches === []) {
        $matches = glob($workDir . '/*.zip') ?: [];
    }
    if ($matches === []) {
        throw new RuntimeException('No .pinx or .zip found inside ' . basename($tarPath));
    }

    return $matches[0];
}

/**
 * @return array{releases: list<array{id: string, path: string, size: int, mtime: int}>}
 */
function pinroll_pincore_incoming(string $incoming): array
{
    $releases = [];
    if (!is_dir($incoming)) {
        return ['releases' => []];
    }

    foreach (scandir($incoming) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $incoming . '/' . $name;
        if (!is_file($path)) {
            continue;
        }
        $releases[] = [
            'id' => preg_replace('/\.(tar|pinx|pin|zip)$/i', '', $name) ?: $name,
            'path' => $name,
            'size' => (int) filesize($path),
            'mtime' => (int) filemtime($path),
        ];
    }

    usort($releases, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

    return ['releases' => $releases];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function pinroll_pincore_cleanup(string $root, string $incoming, array $input): array
{
    $keep = isset($input['keep']) ? max(0, (int) $input['keep']) : 3;
    $staleDays = isset($input['stale_days']) ? max(0, (int) $input['stale_days']) : 7;
    $dryRun = !empty($input['dry_run']);
    $deleted = [];
    $kept = [];
    $bytesFreed = 0;
    $staleCutoff = $staleDays > 0 ? time() - ($staleDays * 86400) : 0;
    $zipCutoff = $staleDays > 0 ? $staleCutoff : time() - 86400;

    $files = [];
    foreach (scandir($incoming) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $incoming . '/' . $name;
        if (!is_file($path)) {
            continue;
        }

        $lower = strtolower($name);
        $isArchive = str_ends_with($lower, '.pinx')
            || str_ends_with($lower, '.zip')
            || str_ends_with($lower, '.tar')
            || str_ends_with($lower, '.tar.gz');

        if ($isArchive) {
            $files[] = ['path' => $path, 'name' => $name, 'mtime' => (int) filemtime($path), 'bytes' => (int) filesize($path), 'archive' => true];

            continue;
        }

        $bytes = (int) filesize($path);
        if (!$dryRun) {
            @unlink($path);
        }
        $bytesFreed += $bytes;
        $deleted[] = [
            'path' => 'incoming/' . $name,
            'bytes' => $bytes,
            'reason' => 'orphan upload artifact',
        ];
    }

    usort($files, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
    foreach ($files as $index => $file) {
        $isStale = $staleCutoff > 0 && $file['mtime'] < $staleCutoff;
        if ($index < $keep && !$isStale) {
            $kept[] = 'incoming/' . $file['name'];

            continue;
        }

        if (!$dryRun) {
            @unlink($file['path']);
        }
        $bytesFreed += $file['bytes'];
        $deleted[] = [
            'path' => 'incoming/' . $file['name'],
            'bytes' => $file['bytes'],
            'reason' => $isStale ? 'older than stale_days=' . $staleDays : 'older than keep=' . $keep,
        ];
    }

    foreach (['platform.zip', 'vendor.zip'] as $zipName) {
        $zipPath = rtrim($root, '/') . '/' . $zipName;
        if (!is_file($zipPath)) {
            continue;
        }
        $mtime = (int) filemtime($zipPath);
        if ($mtime >= $zipCutoff) {
            continue;
        }
        $bytes = (int) filesize($zipPath);
        if (!$dryRun) {
            @unlink($zipPath);
        }
        $bytesFreed += $bytes;
        $deleted[] = [
            'path' => $zipName,
            'bytes' => $bytes,
            'reason' => 'leftover deploy zip',
        ];
    }

    $tmp = rtrim($root, '/') . '/storage/tmp';
    if (is_dir($tmp)) {
        $bytes = pinroll_directory_bytes($tmp);
        if (!$dryRun) {
            pinroll_remove_directory($tmp);
        }
        @mkdir($tmp, 0755, true);
        if ($bytes > 0) {
            $bytesFreed += $bytes;
            $deleted[] = ['path' => 'storage/tmp/', 'bytes' => $bytes, 'reason' => 'temporary workspace'];
        }
    }

    $pinion = rtrim($root, '/') . '/storage/pinion';
    if (is_dir($pinion)) {
        $bytes = pinroll_directory_bytes($pinion);
        if ($bytes > 0) {
            if (!$dryRun) {
                pinroll_remove_directory($pinion);
            }
            $bytesFreed += $bytes;
            $deleted[] = ['path' => 'storage/pinion/', 'bytes' => $bytes, 'reason' => 'temporary workspace'];
        }
    }

    $staging = rtrim($root, '/') . '/storage/pinroll/staging';
    if (is_dir($staging)) {
        $bytes = pinroll_directory_bytes($staging);
        if ($bytes > 0) {
            if (!$dryRun) {
                pinroll_remove_directory($staging);
            }
            $bytesFreed += $bytes;
            $deleted[] = ['path' => 'storage/pinroll/staging/', 'bytes' => $bytes, 'reason' => 'temporary workspace'];
        }
    }

    $legacy = rtrim($root, '/') . '/pinroll';
    if (is_dir($legacy) && !is_file($legacy . '/pinroll.config.php')) {
        $bytes = pinroll_directory_bytes($legacy);
        if (!$dryRun) {
            pinroll_remove_directory($legacy);
        }
        if ($bytes > 0) {
            $bytesFreed += $bytes;
            $deleted[] = ['path' => 'pinroll/', 'bytes' => $bytes, 'reason' => 'legacy workspace'];
        }
    }

    return [
        'dry_run' => $dryRun,
        'keep' => $keep,
        'stale_days' => $staleDays,
        'deleted' => $deleted,
        'kept' => $kept,
        'bytes_freed' => $bytesFreed,
        'files_deleted' => count($deleted),
    ];
}

function pinroll_directory_bytes(string $dir): int
{
    if (!is_dir($dir)) {
        return 0;
    }

    $total = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $total += (int) $file->getSize();
        }
    }

    return $total;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function pinroll_pincore_setup(string $root, array $input): array
{
    pinroll_boot_platform_for_setup($root);

    if (class_exists(\Pinoox\Pinroll\PinGate\HostSetup::class)) {
        $db = is_array($input['db'] ?? null) ? $input['db'] : [];
        $user = is_array($input['user'] ?? null) ? $input['user'] : [];
        $lang = isset($input['lang']) ? (string) $input['lang'] : null;
        foreach (pinroll_default_admin_user() as $key => $value) {
            if (trim((string) ($user[$key] ?? '')) === '') {
                $user[$key] = $value;
            }
        }

        $result = \Pinoox\Pinroll\PinGate\HostSetup::run($root, $db, $user, $lang, !empty($input['force']));
        $finish = pinroll_finish_install($root);

        return array_merge($result, [
            'routes' => $finish['routes'],
            'installer_disabled' => $finish['installer_disabled'],
        ]);
    }

    if (!class_exists(\App\com_pinoox_installer\Component\SetupService::class)) {
        throw new RuntimeException('Installer app is missing on the host. Re-run pinroll:provision.');
    }

    if (empty($input['force']) && pinroll_installer_is_disabled($root)) {
        throw new RuntimeException('This site is already installed (installer is disabled). Pass force=true to re-run setup.');
    }

    $db = is_array($input['db'] ?? null) ? $input['db'] : [];
    $user = is_array($input['user'] ?? null) ? $input['user'] : [];
    $lang = isset($input['lang']) ? (string) $input['lang'] : 'en';
    foreach (pinroll_default_admin_user() as $key => $value) {
        if (trim((string) ($user[$key] ?? '')) === '') {
            $user[$key] = $value;
        }
    }
    $errors = pinroll_validate_provision_payload($db, $user, $lang);
    if ($errors !== []) {
        throw new RuntimeException(implode("\n", $errors));
    }

    \App\com_pinoox_installer\Component\SetupService::make()->run($db, $user, $lang);

    $htaccess = false;
    try {
        (new \App\com_pinoox_installer\Component\HtaccessManager())->create();
        $htaccess = true;
    } catch (Throwable) {
    }

    $finish = pinroll_finish_install($root);

    return [
        'installed' => true,
        'lang' => $lang,
        'htaccess' => $htaccess,
        'routes' => $finish['routes'],
        'installer_disabled' => $finish['installer_disabled'],
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function pinroll_pincore_check_db(string $root, array $input): array
{
    pinroll_boot_platform_for_setup($root);

    if (class_exists(\Pinoox\Pinroll\PinGate\HostSetup::class)) {
        $db = is_array($input['db'] ?? null) ? $input['db'] : [];

        return \Pinoox\Pinroll\PinGate\HostSetup::checkDb($root, $db);
    }

    if (!class_exists(\App\com_pinoox_installer\Component\InstallerDatabase::class)) {
        throw new RuntimeException('Installer database helper is missing on the host.');
    }

    $db = is_array($input['db'] ?? null) ? $input['db'] : [];
    $ok = \App\com_pinoox_installer\Component\InstallerDatabase::testConnection($db);

    return [
        'ok' => $ok,
        'message' => $ok ? 'Database connection succeeded.' : 'Database connection failed from this host.',
    ];
}

function pinroll_boot_platform_for_setup(string $root): void
{
    if (class_exists(\Pinoox\Pinroll\Bridge\PlatformBootstrap::class)) {
        \Pinoox\Pinroll\Bridge\PlatformBootstrap::ensure($root);

        return;
    }

    $root = rtrim(str_replace('\\', '/', $root), '/');
    $launcher = $root . '/platform/launcher';
    $corePathFile = $launcher . '/core-path.php';
    if (!is_file($corePathFile)) {
        throw new RuntimeException('Missing platform/launcher on host. Upload a complete Pinoox platform first.');
    }

    if (!defined('PINOOX_BASE_PATH')) {
        define('PINOOX_BASE_PATH', $root);
    }

    require_once $corePathFile;
    if (!defined('PINOOX_CORE_PATH')) {
        throw new RuntimeException('PINOOX_CORE_PATH could not be resolved.');
    }

    $baseFunctions = rtrim((string) PINOOX_CORE_PATH, '/') . '/functions/base.php';
    if (is_file($baseFunctions)) {
        require_once $baseFunctions;
    }

    $autoload = $root . '/vendor/autoload.php';
    if (is_file($autoload)) {
        $loader = require $autoload;
        $coreAutoload = $launcher . '/core-autoload.php';
        if ($loader instanceof Composer\Autoload\ClassLoader && is_file($coreAutoload)) {
            require_once $coreAutoload;
            if (function_exists('pinoox_register_core_autoload')) {
                pinoox_register_core_autoload($loader, (string) PINOOX_BASE_PATH, (string) PINOOX_CORE_PATH);
            }
        }
        if (class_exists(\Pinoox\Component\Kernel\Loader::class) && $loader instanceof Composer\Autoload\ClassLoader) {
            \Pinoox\Component\Kernel\Loader::set($loader, $root);
        }
    }

    if (class_exists(\Pinoox\Portal\App\AppEngine::class)) {
        \Pinoox\Portal\App\AppEngine::__rebuild();
    }
}

function pinroll_installer_is_disabled(string $root): bool
{
    try {
        if (class_exists(\Pinoox\Portal\App\AppEngine::class)
            && \Pinoox\Portal\App\AppEngine::exists('com_pinoox_installer')
        ) {
            $config = \Pinoox\Portal\App\AppEngine::config('com_pinoox_installer');
            if (is_object($config) && method_exists($config, 'get')) {
                return $config->get('enable') === false;
            }
        }
    } catch (Throwable) {
    }

    $root = rtrim(str_replace('\\', '/', $root), '/');
    foreach ([
        $root . '/pinker/state/apps/com_pinoox_installer/app.php',
        $root . '/apps/com_pinoox_installer/pinker/app.php',
    ] as $file) {
        if (!is_file($file)) {
            continue;
        }
        $data = include $file;
        if (is_array($data) && array_key_exists('enable', $data) && $data['enable'] === false) {
            return true;
        }
    }

    return false;
}

/**
 * @return array<string, string>
 */
function pinroll_default_admin_user(): array
{
    return [
        'fname' => 'support',
        'lname' => 'pinoox',
        'email' => 'info@pinoox.com',
        'username' => 'admin',
        'password' => '123456',
    ];
}

/**
 * Same finish as the web installer: app-router from installer config + disable installer.
 *
 * @return array{routes: array<string, string>, installer_disabled: bool}
 */
function pinroll_finish_install(string $root): array
{
    if (class_exists(\Pinoox\Pinroll\PinGate\HostPostInstall::class)) {
        return \Pinoox\Pinroll\PinGate\HostPostInstall::apply($root);
    }

    $root = rtrim(str_replace('\\', '/', $root), '/');
    $routesFile = $root . '/apps/com_pinoox_installer/config/app.config.php';
    $routes = is_file($routesFile) ? include $routesFile : [];
    if (!is_array($routes) || $routes === []) {
        $routes = [
            '/' => 'com_pinoox_welcome',
            '/manager' => 'com_pinoox_manager',
        ];
    }

    $routerWritten = false;
    try {
        if (class_exists(\Pinoox\Portal\App\AppRouter::class)) {
            \Pinoox\Portal\App\AppRouter::setData($routes);
            $routerWritten = true;
        }
    } catch (Throwable) {
    }
    if (!$routerWritten) {
        $php = "<?php\n\nreturn " . var_export($routes, true) . ";\n";
        foreach ([
            $root . '/platform/app-router.config.php',
            $root . '/pinker/platform/app-router.config.php',
        ] as $file) {
            $dir = dirname($file);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                continue;
            }
            @file_put_contents($file, $php);
        }
    }

    $disabled = false;
    try {
        if (class_exists(\Pinoox\Portal\App\AppEngine::class)
            && \Pinoox\Portal\App\AppEngine::exists('com_pinoox_installer')
        ) {
            \Pinoox\Portal\App\AppEngine::config('com_pinoox_installer')->set('enable', false)->save();
            $disabled = true;
        }
    } catch (Throwable) {
    }

    return [
        'routes' => $routes,
        'installer_disabled' => $disabled,
    ];
}

/**
 * @param array<string, mixed> $db
 * @param array<string, mixed> $user
 * @return list<string>
 */
function pinroll_validate_provision_payload(array $db, array $user, string $lang): array
{
    $errors = [];
    if (trim((string) ($db['host'] ?? '')) === '') {
        $errors[] = 'Database host is required.';
    }
    if (trim((string) ($db['database'] ?? '')) === '') {
        $errors[] = 'Database name is required.';
    }
    if (trim((string) ($db['username'] ?? '')) === '') {
        $errors[] = 'Database username is required.';
    }
    $connection = strtolower(trim((string) ($db['connection'] ?? 'mysql')));
    if ($connection !== '' && !in_array($connection, ['mysql', 'mariadb', 'pgsql', 'sqlsrv'], true)) {
        $errors[] = 'Database connection must be mysql, mariadb, pgsql, or sqlsrv.';
    }
    if (mb_strlen(trim((string) ($user['fname'] ?? ''))) < 3) {
        $errors[] = 'Admin first name must be at least 3 characters.';
    }
    if (mb_strlen(trim((string) ($user['lname'] ?? ''))) < 3) {
        $errors[] = 'Admin last name must be at least 3 characters.';
    }
    $email = trim((string) ($user['email'] ?? ''));
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'Admin email is required and must be valid.';
    }
    $username = trim((string) ($user['username'] ?? ''));
    if ($username === '' || mb_strlen($username) < 3 || preg_match('/^[A-Za-z0-9_-]+$/', $username) !== 1) {
        $errors[] = 'Admin username must be at least 3 ascii letters, numbers, dashes or underscores.';
    }
    if (mb_strlen((string) ($user['password'] ?? '')) < 6) {
        $errors[] = 'Admin password must be at least 6 characters.';
    }
    if (!in_array($lang, ['en', 'fa'], true)) {
        $errors[] = 'Language must be en or fa.';
    }

    return $errors;
}


