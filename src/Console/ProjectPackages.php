<?php

namespace Pinoox\Pinroll\Console;

final class ProjectPackages
{
    public static function defaultPackage(?string $projectRoot = null): string
    {
        $packages = self::list($projectRoot);

        return $packages[0] ?? 'com_pinoox_developer';
    }

    /**
     * @return list<string>
     */
    public static function list(?string $projectRoot = null): array
    {
        $root = $projectRoot ?? (defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd());
        $appsDir = rtrim((string) $root, '/') . '/apps';

        if (!is_dir($appsDir)) {
            return [];
        }

        return array_values(array_filter(scandir($appsDir) ?: [], static function (string $entry): bool {
            return $entry !== '.' && $entry !== '..' && str_starts_with($entry, 'com_');
        }));
    }
}
