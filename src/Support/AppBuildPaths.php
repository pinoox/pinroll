<?php

namespace Pinoox\Pinroll\Support;

final class AppBuildPaths
{
    public static function isMultiApp(string $root, string $package): bool
    {
        $appDir = self::appDir($root, $package);

        return is_dir($appDir) && is_file($appDir . '/app.php');
    }

    public static function appDir(string $root, string $package): string
    {
        return rtrim(str_replace('\\', '/', $root), '/') . '/apps/' . $package;
    }

    public static function pinxExportDir(string $root, string $package): string
    {
        if (self::isMultiApp($root, $package)) {
            return self::appDir($root, $package) . '/pinx/export';
        }

        return rtrim(str_replace('\\', '/', $root), '/') . '/pinx/export/' . $package;
    }

    /**
     * Export directories that may accumulate .pinx build artifacts.
     *
     * @return list<string>
     */
    public static function discoverExportDirs(string $root): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $dirs = [];

        $appsRoot = $root . '/apps';
        if (is_dir($appsRoot)) {
            foreach (scandir($appsRoot) ?: [] as $name) {
                if ($name === '.' || $name === '..' || !str_starts_with($name, 'com_')) {
                    continue;
                }

                $export = $appsRoot . '/' . $name . '/pinx/export';
                if (is_dir($export)) {
                    $dirs[] = $export;
                }
            }
        }

        $single = $root . '/pinx/export';
        if (is_dir($single)) {
            $dirs[] = $single;
            foreach (scandir($single) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $path = $single . '/' . $name;
                if (is_dir($path)) {
                    $dirs[] = $path;
                }
            }
        }

        return array_values(array_unique($dirs));
    }

    public static function nextPinxOutput(string $root, string $package): string
    {
        $exportDir = self::pinxExportDir($root, $package);
        $filename = $package . '_v' . self::versionCode($root, $package) . '_' . date('Ymd_His') . '.pinx';

        return $exportDir . '/' . $filename;
    }

    public static function ensureDir(string $directory): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
    }

    public static function displayPath(string $root, string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    private static function versionCode(string $root, string $package): int
    {
        $appFile = self::isMultiApp($root, $package)
            ? self::appDir($root, $package) . '/app.php'
            : rtrim(str_replace('\\', '/', $root), '/') . '/app.php';

        if (!is_file($appFile)) {
            return 1;
        }

        /** @var array<string, mixed> $config */
        $config = require $appFile;

        return max(1, (int) ($config['version-code'] ?? 1));
    }
}
