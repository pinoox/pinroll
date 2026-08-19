<?php

namespace Pinoox\Pinroll\Support;

final class PlatformRootResolver
{
    /**
     * Resolve Pinoox platform root from pingate.php's directory (__DIR__).
     * A leftover gate/ folder is never treated as the platform root.
     *
     * @param array<string, mixed> $gateConfig
     */
    public static function resolve(string $startDir, array $gateConfig = []): string
    {
        $startDir = rtrim(str_replace('\\', '/', $startDir), '/');
        $configured = trim(str_replace('\\', '/', (string) ($gateConfig['platform_root'] ?? '')));

        if ($configured !== '') {
            $resolved = self::absoluteFromConfig($configured, $startDir);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        if (basename($startDir) !== 'gate' && self::looksLikePlatform($startDir)) {
            return $startDir;
        }

        $current = $startDir;
        for ($depth = 0; $depth < 8; $depth++) {
            $next = dirname($current);
            if ($next === $current) {
                break;
            }
            $current = $next;
            if (basename($current) === 'gate') {
                continue;
            }
            if (self::looksLikePlatform($current)) {
                return $current;
            }
        }

        throw new \RuntimeException(
            'Pinoox platform root not found. Install Pinoox next to pingate.php (same folder as index.php).',
        );
    }

    private static function looksLikePlatform(string $dir): bool
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');

        return is_file($dir . '/vendor/autoload.php')
            || is_file($dir . '/index.php')
            || is_file($dir . '/pinoox');
    }

    private static function absoluteFromConfig(string $configured, string $startDir): ?string
    {
        $isAbsolute = str_starts_with($configured, '/')
            || preg_match('#^[a-zA-Z]:[\\\\/]#', $configured) === 1;

        if (!$isAbsolute) {
            $candidate = rtrim(str_replace('\\', '/', $startDir . '/' . $configured), '/');
            $real = realpath($candidate);
            $candidate = is_string($real) ? $real : $candidate;
        } else {
            $candidate = rtrim(str_replace('\\', '/', $configured), '/');
            $real = realpath($candidate);
            $candidate = is_string($real) ? str_replace('\\', '/', $real) : $candidate;
        }

        return self::looksLikePlatform($candidate) ? $candidate : null;
    }
}
