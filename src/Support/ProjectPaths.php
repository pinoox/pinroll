<?php

namespace Pinoox\Pinroll\Support;

use Pinoox\Pinroll\Contract\PathResolverInterface;

final class ProjectPaths
{
    public static function dir(PathResolverInterface $paths): string
    {
        return rtrim($paths->root(), '/') . '/.pinoox';
    }

    public static function preferredConfigFile(PathResolverInterface $paths): string
    {
        return self::dir($paths) . '/pinroll.config.php';
    }

    public static function legacyConfigFile(PathResolverInterface $paths): string
    {
        return rtrim($paths->root(), '/') . '/pinroll/pinroll.config.php';
    }

    public static function configFile(PathResolverInterface $paths): string
    {
        $preferred = self::preferredConfigFile($paths);
        if (is_file($preferred)) {
            return $preferred;
        }

        $legacy = self::legacyConfigFile($paths);
        if (is_file($legacy)) {
            return $legacy;
        }

        return $preferred;
    }

    public static function workDir(PathResolverInterface $paths): string
    {
        return rtrim($paths->root(), '/') . '/storage/pinroll';
    }

    public static function bundlesDir(PathResolverInterface $paths): string
    {
        return self::dir($paths) . '/pinroll-bundles';
    }

    public static function bundleFile(PathResolverInterface $paths, string $name): string
    {
        return self::bundlesDir($paths) . '/' . $name . '.php';
    }

    public static function isInitialized(PathResolverInterface $paths): bool
    {
        return is_file(self::preferredConfigFile($paths)) || is_file(self::legacyConfigFile($paths));
    }

    public static function gateDir(PathResolverInterface $paths): string
    {
        return self::workDir($paths) . '/gate-build';
    }

    public static function deployZip(PathResolverInterface $paths, string $target): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $target) ?: 'target';

        return self::workDir($paths) . '/deploy-' . $slug . '.zip';
    }

    /** Ready-to-extract PinGate package for File Manager (pinion / no FTP). */
    public static function kitZip(PathResolverInterface $paths, string $target): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $target) ?: 'target';

        return self::workDir($paths) . '/pinroll-kit-' . $slug . '.zip';
    }

    public static function vendorPackZip(PathResolverInterface $paths): string
    {
        return self::workDir($paths) . '/vendor.zip';
    }

    /** @deprecated use vendorPackZip() */
    public static function vendorZip(PathResolverInterface $paths): string
    {
        return self::vendorPackZip($paths);
    }

    /** @deprecated use deployZip() */
    public static function gateZip(PathResolverInterface $paths, string $target): string
    {
        return self::deployZip($paths, $target);
    }
}
