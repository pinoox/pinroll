<?php

namespace Pinoox\Pinroll\PinGate;

/**
 * Same finish step as the web installer: swap app-router and disable com_pinoox_installer.
 */
final class HostPostInstall
{
    public const INSTALLER = 'com_pinoox_installer';

    /**
     * @return array{routes: array<string, string>, installer_disabled: bool, router_written: bool}
     */
    public static function apply(string $root): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $routes = self::postInstallRoutes($root);

        $routerWritten = self::applyRoutes($root, $routes);
        $disabled = self::disableInstaller($root);

        return [
            'routes' => $routes,
            'installer_disabled' => $disabled,
            'router_written' => $routerWritten,
        ];
    }

    /**
     * Routes from apps/com_pinoox_installer/config/app.config.php (welcome + manager).
     *
     * @return array<string, string>
     */
    public static function postInstallRoutes(string $root = ''): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $candidates = [];
        if ($root !== '') {
            $candidates[] = $root . '/apps/' . self::INSTALLER . '/config/app.config.php';
        }
        try {
            if (class_exists(\Pinoox\Portal\App\AppEngine::class)
                && \Pinoox\Portal\App\AppEngine::exists(self::INSTALLER)
            ) {
                $candidates[] = \Pinoox\Portal\App\AppEngine::path(self::INSTALLER, 'config/app.config.php');
            }
        } catch (\Throwable) {
        }

        foreach ($candidates as $file) {
            if (!is_string($file) || $file === '' || !is_file($file)) {
                continue;
            }
            $data = include $file;
            if (is_array($data) && $data !== []) {
                $routes = [];
                foreach ($data as $path => $package) {
                    if (is_string($path) && is_string($package) && $package !== '') {
                        $routes[$path] = $package;
                    }
                }
                if ($routes !== []) {
                    return $routes;
                }
            }
        }

        return [
            '/' => 'com_pinoox_welcome',
            '/manager' => 'com_pinoox_manager',
        ];
    }

    /**
     * @param array<string, string> $routes
     */
    private static function applyRoutes(string $root, array $routes): bool
    {
        try {
            if (class_exists(\Pinoox\Portal\App\AppRouter::class)) {
                \Pinoox\Portal\App\AppRouter::setData($routes);

                return true;
            }
        } catch (\Throwable) {
        }

        $php = "<?php\n\nreturn " . var_export($routes, true) . ";\n";
        $written = false;
        foreach ([
            $root . '/platform/app-router.config.php',
            $root . '/pinker/platform/app-router.config.php',
        ] as $file) {
            $dir = dirname($file);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                continue;
            }
            if (@file_put_contents($file, $php) !== false) {
                $written = true;
            }
        }

        return $written;
    }

    private static function disableInstaller(string $root): bool
    {
        try {
            if (class_exists(\Pinoox\Portal\App\AppEngine::class)
                && \Pinoox\Portal\App\AppEngine::exists(self::INSTALLER)
            ) {
                \Pinoox\Portal\App\AppEngine::config(self::INSTALLER)
                    ->set('enable', false)
                    ->save();

                return true;
            }
        } catch (\Throwable) {
        }

        $ok = false;
        foreach ([
            $root . '/pinker/apps/' . self::INSTALLER . '/app.php',
            $root . '/apps/' . self::INSTALLER . '/pinker/app.php',
            $root . '/pinker/state/apps/' . self::INSTALLER . '/app.php',
        ] as $file) {
            if (self::writeEnableFalse($file, is_file($file))) {
                $ok = true;
            }
        }

        return $ok;
    }

    private static function writeEnableFalse(string $file, bool $mergeExisting): bool
    {
        $data = ['enable' => false];
        if ($mergeExisting) {
            $existing = include $file;
            if (is_array($existing)) {
                $data = $existing;
                $data['enable'] = false;
                if (isset($data['data']) && is_array($data['data'])) {
                    $data['data']['enable'] = false;
                }
            }
        }

        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        return @file_put_contents($file, "<?php\n\nreturn " . var_export($data, true) . ";\n") !== false;
    }
}
