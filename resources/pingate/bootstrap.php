<?php

declare(strict_types=1);

/**
 * PinGate HTTP bootstrap — resolve platform root, then load platform vendor/autoload.php.
 * Optional gate/vendor is only a fallback (--with-vendor); never treated as platform root.
 */
return static function (string $configDir): void {
    $configDir = rtrim(str_replace('\\', '/', $configDir), '/');
    $configFile = $configDir . '/pingate.php';
    /** @var array<string, mixed> $gateConfig */
    $gateConfig = is_file($configFile) ? require $configFile : [];

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = trim((string) ($_GET['route'] ?? ''), '/');

    // Early vendor extract — must work even when platform vendor/autoload is broken.
    if ($method === 'POST' && $path === 'vendor') {
        try {
            $root = pinroll_resolve_platform_root($configDir, $gateConfig);
        } catch (Throwable $e) {
            $parent = dirname($configDir);
            if ($parent === $configDir || !pinroll_looks_like_platform_root($parent)) {
                pinroll_gate_json_error(503, 'Platform root not found for vendor extract.');

                return;
            }
            $root = $parent;
        }

        $input = json_decode((string) file_get_contents('php://input'), true);
        pinroll_handle_vendor_extract(
            $root,
            $gateConfig,
            is_array($input) ? $input : [],
            $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null),
        );

        return;
    }

    try {
        $root = pinroll_resolve_platform_root($configDir, $gateConfig);
    } catch (Throwable $e) {
        pinroll_gate_json_error(503, $e->getMessage());

        return;
    }

    if (!defined('PINOOX_BASE_PATH')) {
        define('PINOOX_BASE_PATH', $root);
    }

    pinroll_load_platform_autoload($root);

    $gateVendor = $configDir . '/vendor/autoload.php';
    if (is_file($gateVendor)) {
        require_once $gateVendor;
    }

    if (!pinroll_ensure_pinroll_classes($root)) {
        return;
    }

    \Pinoox\Pinroll\Pinroll::configure([], new \Pinoox\Pinroll\Support\NativePathResolver($root));

    $input = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? null;

    try {
        $result = \Pinoox\Pinroll\Pinroll::gate()->handle($method, $path, is_array($input) ? $input : [], $auth, $configFile);
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        pinroll_gate_json_error((int) ($e->getCode() ?: 500), $e->getMessage());
    }
};

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
 * Platform vendor must include a complete pinoox/pinroll (path-repo packs often miss files).
 */
function pinroll_ensure_pinroll_classes(string $root): bool
{
    $pinrollRoot = pinroll_package_root($root);
    $required = [
        'Pinoox\\Pinroll\\Pinroll' => 'src/Pinroll.php',
        'Pinoox\\Pinroll\\Exception\\PinrollException' => 'src/Exception/PinrollException.php',
    ];

    foreach ($required as $class => $relative) {
        if (class_exists($class, true)) {
            continue;
        }

        $file = $pinrollRoot !== null ? $pinrollRoot . '/' . $relative : null;
        if ($file !== null && is_file($file)) {
            require_once $file;
        }

        if (!class_exists($class, false)) {
            pinroll_gate_json_error(
                503,
                'Incomplete pinoox/pinroll on host (missing ' . $class . '). '
                . 'On your machine: php pinoox pinroll:vendor — upload pinroll/vendor.zip '
                . 'and extract into the deploy root so vendor/pinoox/pinroll/src/ is complete.',
            );

            return false;
        }
    }

    return true;
}

function pinroll_package_root(string $platformRoot): ?string
{
    $candidates = [
        rtrim($platformRoot, '/') . '/vendor/pinoox/pinroll',
        rtrim($platformRoot, '/') . '/vendor/pinoox/pinroll/src/..',
    ];

    foreach ($candidates as $candidate) {
        $real = realpath($candidate);
        if ($real !== false && is_file($real . '/src/Pinroll.php')) {
            return $real;
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $gateConfig
 */
function pinroll_resolve_platform_root(string $startDir, array $gateConfig = []): string
{
    $startDir = rtrim(str_replace('\\', '/', $startDir), '/');
    $configured = trim(str_replace('\\', '/', (string) ($gateConfig['platform_root'] ?? '')));

    if ($configured !== '') {
        $resolved = pinroll_absolute_platform_root($configured, $startDir);
        if ($resolved !== null) {
            return $resolved;
        }
    }

    // Prefer parent of gate/ (deploy root) — never treat gate/vendor as platform.
    $parent = dirname($startDir);
    if ($parent !== $startDir && pinroll_looks_like_platform_root($parent)) {
        return $parent;
    }

    $current = $parent !== $startDir ? $parent : $startDir;

    for ($depth = 0; $depth < 8; $depth++) {
        if ($current !== $startDir && pinroll_looks_like_platform_root($current)) {
            return $current;
        }

        $next = dirname($current);
        if ($next === $current) {
            break;
        }

        $current = $next;
    }

    throw new RuntimeException(
        'Pinoox platform root not found. Install Pinoox next to pingate.php (same folder as gate/).',
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
    if ($configured === '..' || str_starts_with($configured, '../') || str_starts_with($configured, './')) {
        $candidate = rtrim(str_replace('\\', '/', $startDir . '/' . $configured), '/');
        $real = realpath($candidate);
        $candidate = is_string($real) ? $real : $candidate;
    } elseif (!str_starts_with($configured, '/')) {
        $candidate = rtrim(str_replace('\\', '/', $startDir . '/' . $configured), '/');
        $real = realpath($candidate);
        $candidate = is_string($real) ? $real : $candidate;
    } else {
        $candidate = rtrim($configured, '/');
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

    $expectedHash = (string) ($gateConfig['token_hash'] ?? '');
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
    $backupDir = $root . '/.pinroll-vendor-bak-' . bin2hex(random_bytes(4));

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

    // Always remove the zip after a successful extract — do not leave it web-reachable.
    $deletedZip = @unlink($zipReal);

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
    $dir = rtrim($root, '/') . '/gate';
    if (!is_dir($dir)) {
        $dir = rtrim($root, '/') . '/storage/pinroll/gate';
    }
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

    // Never follow a rename into deleting arbitrary trees without a vendor marker.
    $normalized = str_replace('\\', '/', $real);
    if (!str_contains($normalized, '/vendor') && !str_contains(basename($normalized), 'pinroll-vendor-bak')) {
        return;
    }

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
            pinroll_remove_directory($full);
            continue;
        }

        @unlink($full);
    }

    @rmdir($real);
}
