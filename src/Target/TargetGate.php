<?php

namespace Pinoox\Pinroll\Target;

use Pinoox\Pinroll\Console\ConfigWriter;
use Pinoox\Pinroll\Support\HostDir;

/**
 * PinGate credentials — one top-level gate { url, token } per target (shared by all transports).
 */
final class TargetGate
{
    public const EXAMPLE_DOMAIN = 'pinoox.com';
    /** Empty = site root (https://pinoox.com/pingate.php). */
    public const EXAMPLE_DIR = '';

    /**
     * @param array<string, mixed> $target Raw or resolved target config
     * @return array{url: string, token: string}
     */
    public static function credentials(array $target, ?string $via = null): array
    {
        unset($via);

        if (is_array($target['gate'] ?? null)) {
            $top = self::readGateArray($target['gate']);
            if ($top['url'] !== '' || $top['token'] !== '') {
                return $top;
            }
        }

        // Legacy: nested ftp.gate / ssh.gate
        foreach (['ftp', 'ssh'] as $transport) {
            if (!is_array($target[$transport] ?? null)) {
                continue;
            }

            $nested = self::readGateArray($target[$transport]['gate'] ?? null);
            if ($nested['url'] !== '' || $nested['token'] !== '') {
                return $nested;
            }
        }

        // Legacy: pinion block or flat fields
        if (is_array($target['pinion'] ?? null)) {
            $pinion = self::readGateArray($target['pinion']);
            if ($pinion['url'] !== '' || $pinion['token'] !== '') {
                return $pinion;
            }
        }

        return [
            'url' => trim((string) ($target['gate_url'] ?? '')),
            'token' => trim((string) ($target['token'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $target
     */
    public static function isConfigured(array $target, ?string $via = null): bool
    {
        $credentials = self::credentials($target, $via);

        return $credentials['url'] !== '' && $credentials['token'] !== '';
    }

    /**
     * @return list<string>
     */
    public static function setupGuide(string $targetName, ?string $via = 'ftp'): array
    {
        unset($via);
        $urlKey = ConfigWriter::envKeyFor($targetName, 'url', 'pinion');
        $tokenKey = ConfigWriter::envKeyFor($targetName, 'token', 'pinion');

        return [
            'PinGate is not configured for apply after upload.',
            '',
            '1. Export platform vendor (core + deps for the host):',
            '   php pinoox pinroll:vendor',
            '   → upload pinroll/vendor.zip and extract next to pingate.php (replace vendor/ when updating core)',
            '',
            '2. Install PinGate (FTP uploads automatically when configured):',
            '   php pinoox pinroll:gate ' . $targetName,
            '   → optional zip: php pinoox pinroll:gate ' . $targetName . ' -z',
            '',
            '3. Add to .env:',
            '   ' . $urlKey . '=' . self::exampleUrl(),
            '   ' . $tokenKey . '=<token from pinroll:gate>',
            '',
            '4. Add top-level gate in pinroll.config.php:',
            self::configSnippet($targetName),
            '',
            '5. Push, then apply on the target (not local):',
            '   php pinoox pinroll:push ' . $targetName,
            '   php pinoox pinroll:apply ' . $targetName,
            '   Or in one step: php pinoox pinroll:push ' . $targetName . ' -a',
        ];
    }

    public static function configSnippet(string $targetName): string
    {
        $urlKey = ConfigWriter::envKeyFor($targetName, 'url', 'pinion');
        $tokenKey = ConfigWriter::envKeyFor($targetName, 'token', 'pinion');

        return implode("\n", [
            "'gate' => [",
            "    'url' => env('{$urlKey}', ''),",
            "    'token' => env('{$tokenKey}', ''),",
            '],',
        ]);
    }

    public static function exampleUrl(?string $dir = null): string
    {
        if ($dir === null) {
            return HostDir::gateUrlFromDomain(self::EXAMPLE_DOMAIN, self::EXAMPLE_DIR);
        }

        return HostDir::gateUrlFromDomain(self::EXAMPLE_DOMAIN, HostDir::normalize($dir));
    }

    /**
     * @param array<string, mixed> $target
     */
    public static function suggestedUrl(array $target): string
    {
        $configured = self::credentials($target);
        if ($configured['url'] !== '') {
            return $configured['url'];
        }

        $dir = HostDir::fromTarget($target);

        return self::exampleUrl($dir !== '' ? $dir : null);
    }

    /**
     * @param array<string, mixed> $target
     */
    public static function ftpHost(array $target): string
    {
        if (is_array($target['ftp'] ?? null)) {
            return trim((string) ($target['ftp']['host'] ?? ''));
        }

        return trim((string) ($target['host'] ?? ''));
    }

    /**
     * @param mixed $gate
     * @return array{url: string, token: string}
     */
    private static function readGateArray(mixed $gate): array
    {
        if (!is_array($gate)) {
            return ['url' => '', 'token' => ''];
        }

        return [
            'url' => trim((string) ($gate['url'] ?? $gate['gate_url'] ?? '')),
            'token' => trim((string) ($gate['token'] ?? '')),
        ];
    }
}
