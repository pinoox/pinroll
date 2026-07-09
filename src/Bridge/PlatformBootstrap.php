<?php

namespace Pinoox\Pinroll\Bridge;

use Pinoox\Pinroll\Exception\PinrollException;

/**
 * Minimal Pinoox boot for PinGate apply (portals + Pinx installer).
 * Does not run the HTTP/CLI AppProvider front controller.
 */
final class PlatformBootstrap
{
    private static bool $booted = false;

    public static function ensure(string $root): void
    {
        if (self::$booted) {
            return;
        }

        $root = rtrim(str_replace('\\', '/', $root), '/');
        if ($root === '') {
            throw new PinrollException('Platform root is empty.');
        }

        $launcher = $root . '/platform/launcher';
        $corePathFile = $launcher . '/core-path.php';
        if (!is_file($corePathFile)) {
            throw new PinrollException(
                'Missing platform/launcher on host. Upload a complete Pinoox platform (not only vendor/).',
            );
        }

        if (!defined('PINOOX_BASE_PATH')) {
            define('PINOOX_BASE_PATH', $root);
        } elseif (rtrim(str_replace('\\', '/', (string) PINOOX_BASE_PATH), '/') !== $root) {
            throw new PinrollException(
                'PINOOX_BASE_PATH already set to a different root: ' . PINOOX_BASE_PATH,
            );
        }

        // Prefer vendor pincore when local .pincore (path-repo sibling) is missing on host.
        self::preferVendorCoreIfNeeded($root);

        require_once $corePathFile;

        if (!defined('PINOOX_CORE_PATH')) {
            throw new PinrollException('PINOOX_CORE_PATH could not be resolved.');
        }

        $baseFunctions = rtrim((string) PINOOX_CORE_PATH, '/') . '/functions/base.php';
        if (!is_file($baseFunctions)) {
            throw new PinrollException(
                'Incomplete pincore on host (missing functions/base.php). '
                . 'Re-upload pinroll/vendor.zip and extract into the deploy root.',
            );
        }

        require_once $baseFunctions;

        $autoload = $root . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new PinrollException('Missing vendor/autoload.php on host.');
        }

        $loader = require $autoload;
        $coreAutoload = $launcher . '/core-autoload.php';
        if ($loader instanceof \Composer\Autoload\ClassLoader && is_file($coreAutoload)) {
            require_once $coreAutoload;
            if (function_exists('pinoox_register_core_autoload')) {
                pinoox_register_core_autoload($loader, (string) PINOOX_BASE_PATH, (string) PINOOX_CORE_PATH);
            }
        }

        // Critical on shared hosts: without Loader base path, ~/platform resolves to /platform/...
        // and open_basedir blocks is_file('/platform/apps.config.php').
        if (class_exists(\Pinoox\Component\Kernel\Loader::class)) {
            if ($loader instanceof \Composer\Autoload\ClassLoader) {
                \Pinoox\Component\Kernel\Loader::set($loader, $root);
            } else {
                \Pinoox\Component\Kernel\Loader::setBasePath($root);
            }
        }

        if (class_exists(\Pinoox\Component\Helpers\EnvBootstrap::class)) {
            \Pinoox\Component\Helpers\EnvBootstrap::load((string) PINOOX_BASE_PATH);
        }

        self::sanitizeHostProjectEnv($root);

        if (class_exists(\Pinoox\Support\SystemConfig::class)) {
            \Pinoox\Support\SystemConfig::clearCache();
        }

        if (class_exists(\Pinoox\Component\File::class) && class_exists(\Pinoox\Support\SystemConfig::class)) {
            $storage = \Pinoox\Support\SystemConfig::path('storage');
            if ($storage !== '' && !str_starts_with($storage, '/platform') && is_dir(dirname($storage))) {
                \Pinoox\Component\File::ensureStorageRootHtaccess($storage);
            }
            \Pinoox\Support\SystemConfig::ensureProjectConfigFiles();
        }

        if (!class_exists(\Pinoox\Portal\App\AppEngine::class)) {
            throw new PinrollException(
                'Pinoox\\Portal\\App\\AppEngine not found. Host vendor/pinoox/pincore is incomplete — '
                . 're-upload pinroll/vendor.zip (replace vendor/).',
            );
        }

        \Pinoox\Portal\App\AppEngine::__rebuild();

        if (!class_exists(\Pinoox\Portal\Pinx::class)) {
            throw new PinrollException('Pinoox\\Portal\\Pinx not found after platform boot.');
        }

        // Ensure Pinx portal is registered (depends on AppEngine service).
        \Pinoox\Portal\Pinx::__rebuild();

        self::$booted = true;
    }

    public static function reset(): void
    {
        self::$booted = false;
    }

    private static function preferVendorCoreIfNeeded(string $root): void
    {
        $vendorCore = $root . '/vendor/pinoox/pincore';
        if (!is_file($vendorCore . '/functions/base.php')) {
            return;
        }

        $envCore = getenv('PINOOX_CORE_PATH') ?: '';
        if (is_string($envCore) && $envCore !== '' && is_file(rtrim($envCore, '/') . '/functions/base.php')) {
            return;
        }

        $dot = $root . '/.pincore';
        if (!is_file($dot)) {
            return;
        }

        $configured = trim((string) file_get_contents($dot));
        if ($configured === '') {
            return;
        }

        $candidate = str_replace('\\', '/', $configured);
        if (!preg_match('/^[A-Za-z]:\//', $candidate) && !str_starts_with($candidate, '/')) {
            $candidate = $root . '/' . ltrim($candidate, '/');
        }
        $candidate = rtrim($candidate, '/');

        if (is_file($candidate . '/functions/base.php')) {
            return;
        }

        putenv('PINOOX_CORE_PATH=' . $vendorCore);
        $_ENV['PINOOX_CORE_PATH'] = $vendorCore;
        $_SERVER['PINOOX_CORE_PATH'] = $vendorCore;
    }

    /**
     * Local .env often has PINOOX_PROJECT_REGISTRY_PATH=~/platform/apps.config.local.php.
     * On host, force defaults under the real deploy root when the configured file is missing.
     */
    private static function sanitizeHostProjectEnv(string $root): void
    {
        $defaults = [
            'PINOOX_PROJECT_CONFIG_PATH' => '~/platform',
            'PINOOX_PROJECT_REGISTRY_PATH' => '~/platform/apps.config.php',
            'PINOOX_PROJECT_ROUTER_PATH' => '~/platform/app-router.config.php',
            'PINOOX_PROJECT_DOMAIN_PATH' => '~/platform/domain.config.php',
            'PINOOX_PROJECT_PINOOX_PATH' => '~/platform/pinoox.config.php',
        ];

        foreach ($defaults as $key => $fallback) {
            $current = (string) ($_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: '');
            if ($current === '') {
                continue;
            }

            $resolved = self::resolveEnvPath($root, $current);
            if ($resolved !== null && (is_file($resolved) || is_dir($resolved))) {
                continue;
            }

            // Missing local-only files (apps.config.local.php) or broken absolute /platform/...
            putenv($key . '=' . $fallback);
            $_ENV[$key] = $fallback;
            $_SERVER[$key] = $fallback;
        }
    }

    private static function resolveEnvPath(string $root, string $path): ?string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return null;
        }

        if ($path === '~' || $path === '~/') {
            return $root;
        }

        if (str_starts_with($path, '~/')) {
            return $root . '/' . substr($path, 2);
        }

        if (preg_match('/^[A-Za-z]:\//', $path) === 1 || str_starts_with($path, '/')) {
            return rtrim($path, '/');
        }

        return $root . '/' . ltrim($path, '/');
    }
}
