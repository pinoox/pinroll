<?php

declare(strict_types=1);

/**
 * PinGate HTTP bootstrap — resolve platform root first, then load platform vendor.
 * Bundled gate/vendor is never treated as the Pinoox platform root.
 */
return static function (string $configDir): void {
    $configDir = rtrim(str_replace('\\', '/', $configDir), '/');
    $configFile = $configDir . '/pingate.php';
    /** @var array<string, mixed> $gateConfig */
    $gateConfig = is_file($configFile) ? require $configFile : [];

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

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = trim((string) ($_GET['route'] ?? ''), '/');
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
