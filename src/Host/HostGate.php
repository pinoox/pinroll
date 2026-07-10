<?php

namespace Pinoox\Pinroll\Host;

use Pinoox\Pinroll\Console\ConfigWriter;
use Pinoox\Pinroll\Support\HostDir;

/**
 * PinGate credentials — one top-level gate { url, token } per host (shared by all transports).
 */
final class HostGate
{
    public const EXAMPLE_DOMAIN = 'pinoox.com';
    public const EXAMPLE_DIR = '';

    /**
     * @param array<string, mixed> $host Raw or resolved host config
     * @return array{url: string, token: string}
     */
    public static function credentials(array $host, ?string $via = null): array
    {
        unset($via);

        if (is_array($host['gate'] ?? null)) {
            $top = self::readGateArray($host['gate']);
            if ($top['url'] !== '' || $top['token'] !== '') {
                return $top;
            }
        }

        foreach (['ftp', 'ssh'] as $transport) {
            if (!is_array($host[$transport] ?? null)) {
                continue;
            }

            $nested = self::readGateArray($host[$transport]['gate'] ?? null);
            if ($nested['url'] !== '' || $nested['token'] !== '') {
                return $nested;
            }
        }

        if (is_array($host['pinion'] ?? null)) {
            $pinion = self::readGateArray($host['pinion']);
            if ($pinion['url'] !== '' || $pinion['token'] !== '') {
                return $pinion;
            }
        }

        return [
            'url' => trim((string) ($host['gate_url'] ?? '')),
            'token' => trim((string) ($host['token'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $host
     */
    public static function isConfigured(array $host, ?string $via = null): bool
    {
        $credentials = self::credentials($host, $via);

        return $credentials['url'] !== '' && $credentials['token'] !== '';
    }

    /**
     * @return list<string>
     */
    public static function setupGuide(string $hostName, ?string $via = 'ftp'): array
    {
        unset($via);
        $urlKey = ConfigWriter::envKeyFor($hostName, 'url', 'pinion');
        $tokenKey = ConfigWriter::envKeyFor($hostName, 'token', 'pinion');

        return [
            'PinGate is not configured for install after upload.',
            '',
            '1. Export platform vendor (core + deps for the host):',
            '   php pinoox pinroll:vendor',
            '   → upload pinroll/vendor.zip and extract next to pingate.php (replace vendor/ when updating core)',
            '',
            '2. Install PinGate (FTP uploads automatically when configured):',
            '   php pinoox pinroll:gate ' . $hostName,
            '   → optional zip: php pinoox pinroll:gate ' . $hostName . ' -z',
            '',
            '3. Add to .env:',
            '   ' . $urlKey . '=' . self::exampleUrl(),
            '   ' . $tokenKey . '=<token from pinroll:gate>',
            '',
            '4. Add top-level gate in pinroll.config.php:',
            self::configSnippet($hostName),
            '',
            '5. Push, then install — or go live in one step:',
            '   php pinoox pinroll:push ' . $hostName,
            '   php pinoox pinroll:install ' . $hostName,
            '   Or: php pinoox pinroll:deploy ' . $hostName,
        ];
    }

    public static function configSnippet(string $hostName): string
    {
        $urlKey = ConfigWriter::envKeyFor($hostName, 'url', 'pinion');
        $tokenKey = ConfigWriter::envKeyFor($hostName, 'token', 'pinion');

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
     * @param array<string, mixed> $host
     */
    public static function suggestedUrl(array $host): string
    {
        $configured = self::credentials($host);
        if ($configured['url'] !== '') {
            return $configured['url'];
        }

        $dir = HostDir::fromHost($host);
        $web = HostDir::webPath($dir);

        return self::exampleUrl($web !== '' ? $web : null);
    }

    /**
     * @param array<string, mixed> $host
     */
    public static function ftpHost(array $host): string
    {
        if (is_array($host['ftp'] ?? null)) {
            return trim((string) ($host['ftp']['host'] ?? ''));
        }

        return trim((string) ($host['host'] ?? $host['hostname'] ?? ''));
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
