<?php

namespace Pinoox\Pinroll\Support;

use Pinoox\Pinroll\Exception\PinrollException;

final class PincorePaths
{
    public const REMOTE_VENDOR_PINCORE = 'vendor/pinoox/pincore';

    /**
     * Find a local pincore tree (vendor symlink/path-repo or project pincore/ fork).
     */
    public static function resolveLocal(string $projectRoot, ?string $override = null): string
    {
        if ($override !== null && $override !== '') {
            return SyncPathValidator::localDir($override, $projectRoot);
        }

        $candidates = [
            rtrim($projectRoot, '/') . '/vendor/pinoox/pincore',
            rtrim($projectRoot, '/') . '/pincore',
        ];

        foreach ($candidates as $candidate) {
            if (!is_dir($candidate)) {
                continue;
            }

            if (self::looksLikePincore($candidate)) {
                $real = realpath($candidate);

                return is_string($real) ? str_replace('\\', '/', $real) : str_replace('\\', '/', $candidate);
            }
        }

        throw new PinrollException(
            "Local pincore not found. Run composer install, or pass --from=/path/to/pincore\n"
            . 'Checked: vendor/pinoox/pincore and pincore/',
        );
    }

    public static function looksLikePincore(string $dir): bool
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        if (!is_dir($dir)) {
            return false;
        }

        if (is_file($dir . '/Portal/View.php') || is_file($dir . '/Component/Kernel/Kernel.php')) {
            return true;
        }

        $composer = $dir . '/composer.json';
        if (!is_file($composer)) {
            return false;
        }

        $raw = file_get_contents($composer);
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($data) && (string) ($data['name'] ?? '') === 'pinoox/pincore';
    }
}
