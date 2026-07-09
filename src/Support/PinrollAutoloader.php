<?php

namespace Pinoox\Pinroll\Support;

/**
 * Load Pinroll without requiring pinoox/pinroll in the platform composer.json.
 */
final class PinrollAutoloader
{
    public static function register(?string $platformRoot = null): void
    {
        if (class_exists(\Pinoox\Pinroll\Pinroll::class, false)) {
            return;
        }

        $platformRoot = rtrim(str_replace('\\', '/', (string) ($platformRoot ?? (defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd()))), '/');

        foreach (self::autoloadCandidates($platformRoot) as $autoload) {
            if (is_file($autoload)) {
                require_once $autoload;

                return;
            }
        }
    }

    /**
     * @return list<string>
     */
    public static function autoloadCandidates(string $platformRoot): array
    {
        $candidates = [
            $platformRoot . '/vendor/pinoox/pinroll/vendor/autoload.php',
            $platformRoot . '/../pinroll/vendor/autoload.php',
            dirname($platformRoot) . '/pinroll/vendor/autoload.php',
        ];

        return array_values(array_unique($candidates));
    }
}
