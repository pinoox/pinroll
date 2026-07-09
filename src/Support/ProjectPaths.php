<?php

namespace Pinoox\Pinroll\Support;

use Pinoox\Pinroll\Contract\PathResolverInterface;

final class ProjectPaths
{
    public static function dir(PathResolverInterface $paths): string
    {
        return rtrim($paths->root(), '/') . '/pinroll';
    }

    public static function configFile(PathResolverInterface $paths): string
    {
        return self::dir($paths) . '/pinroll.config.php';
    }

    public static function bundlesDir(PathResolverInterface $paths): string
    {
        return self::dir($paths) . '/bundles';
    }

    public static function bundleFile(PathResolverInterface $paths, string $name): string
    {
        return self::bundlesDir($paths) . '/' . $name . '.php';
    }

    public static function isInitialized(PathResolverInterface $paths): bool
    {
        return is_file(self::configFile($paths));
    }

    public static function gateDir(PathResolverInterface $paths): string
    {
        return self::dir($paths) . '/gate';
    }

    public static function deployZip(PathResolverInterface $paths, string $target): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $target) ?: 'target';

        return self::dir($paths) . '/deploy-' . $slug . '.zip';
    }

    public static function vendorPackZip(PathResolverInterface $paths): string
    {
        return self::dir($paths) . '/vendor.zip';
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
