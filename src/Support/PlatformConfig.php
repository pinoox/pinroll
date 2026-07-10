<?php

namespace Pinoox\Pinroll\Support;

/**
 * Read platform manifest files (apps registry, build settings).
 */
final class PlatformConfig
{
    /**
     * @return array<string, mixed>
     */
    public static function buildSettings(string $root): array
    {
        $path = self::resolve($root, 'platform/build.config.php');
        if (!is_file($path)) {
            return [];
        }

        /** @var array<string, mixed> $config */
        $config = require $path;

        return $config;
    }

    /**
     * External app entries from apps.config.php (+ local override).
     *
     * @return array<string, mixed>
     */
    public static function externalPackages(string $root): array
    {
        $merged = [];

        foreach (['platform/apps.config.php', 'platform/apps.config.local.php'] as $relative) {
            $path = self::resolve($root, $relative);
            if (!is_file($path)) {
                continue;
            }

            /** @var array<string, mixed> $config */
            $config = require $path;
            $packages = $config['packages'] ?? [];
            if (!is_array($packages)) {
                continue;
            }

            foreach ($packages as $name => $entry) {
                if (!is_string($name) || $name === '') {
                    continue;
                }

                $merged[$name] = $entry;
            }
        }

        return $merged;
    }

    public static function resolve(string $root, string $relative): string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');

        if (str_starts_with($relative, '~/')) {
            return $root . '/' . substr($relative, 2);
        }

        return $root . '/' . $relative;
    }
}
