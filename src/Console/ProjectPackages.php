<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Release\PlatformProfile;

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

        try {
            return PlatformProfile::discoverPackages((string) $root);
        } catch (\Throwable) {
            return [];
        }
    }
}
