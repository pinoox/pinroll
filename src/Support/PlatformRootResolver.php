<?php

namespace Pinoox\Pinroll\Support;

final class PlatformRootResolver
{
    /**
     * Resolve Pinoox platform root from a PinGate config directory (…/gate).
     * Never treats gate/vendor as the platform root.
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

        $parent = dirname($startDir);
        if ($parent !== $startDir && self::looksLikePlatform($parent)) {
            return $parent;
        }

        $current = $parent !== $startDir ? $parent : $startDir;

        for ($depth = 0; $depth < 8; $depth++) {
            if ($current !== $startDir && self::looksLikePlatform($current)) {
                return $current;
            }

            $next = dirname($current);
            if ($next === $current) {
                break;
            }

            $current = $next;
        }

        throw new \RuntimeException(
            'Pinoox platform root not found. Install Pinoox next to pingate.php (same folder as gate/).',
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
        if ($configured === '..' || str_starts_with($configured, '../') || str_starts_with($configured, './') || !str_starts_with($configured, '/')) {
            $candidate = rtrim(str_replace('\\', '/', $startDir . '/' . $configured), '/');
            $real = realpath($candidate);
            $candidate = is_string($real) ? $real : $candidate;
        } else {
            $candidate = rtrim($configured, '/');
        }

        return self::looksLikePlatform($candidate) ? $candidate : null;
    }
}
