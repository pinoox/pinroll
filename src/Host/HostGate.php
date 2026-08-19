<?php

namespace Pinoox\Pinroll\Host;

use Pinoox\Pinroll\Console\GateUrl;
use Pinoox\Pinroll\Support\HostDir;

/**
 * PinGate credentials — one top-level gate { site, token } per host (shared by all transports).
 */
final class HostGate
{
    public const EXAMPLE_DOMAIN = 'pinoox.com';
    public const EXAMPLE_DIR = '';

    /**
     * @param array<string, mixed> $host Raw or resolved host config
     * @return array{url: string, token: string, site: string}
     */
    public static function credentials(array $host, ?string $via = null): array
    {
        unset($via);

        if (is_array($host['gate'] ?? null)) {
            $top = self::readGateArray($host['gate']);
            if ($top['url'] !== '' || $top['token'] !== '' || $top['site'] !== '') {
                return $top;
            }
        }

        foreach (['ftp', 'ssh'] as $transport) {
            if (!is_array($host[$transport] ?? null)) {
                continue;
            }

            $nested = self::readGateArray($host[$transport]['gate'] ?? null);
            if ($nested['url'] !== '' || $nested['token'] !== '' || $nested['site'] !== '') {
                return $nested;
            }
        }

        if (is_array($host['pinion'] ?? null)) {
            $pinion = self::readGateArray($host['pinion']);
            if ($pinion['url'] !== '' || $pinion['token'] !== '' || $pinion['site'] !== '') {
                return $pinion;
            }
        }

        $legacyUrl = trim((string) ($host['gate_url'] ?? ''));
        $legacyToken = trim((string) ($host['token'] ?? ''));

        return self::normalizeCredentials($legacyUrl, $legacyToken, '');
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

        return [
            'PinGate is not configured for install after upload.',
            '',
            '1. Export platform vendor (core + deps for the host):',
            '   php pinoox pinroll:vendor --push',
            '   → builds production vendor.zip (PlatformComposer), FTP upload, PinGate extract',
            '   (pinroll can stay in require-dev; the host uses pingate.php + pincore)',
            '',
            '2. Install PinGate (FTP uploads automatically when configured):',
            '   php pinoox pinroll:gate ' . $hostName,
            '   → optional zip: php pinoox pinroll:gate ' . $hostName . ' -z',
            '',
            '3. Share ONE host token with teammates (1Password / copy). Do not --rotate unless you intend to invalidate everyone.',
            '   Store site origin + token in .pinoox/pinroll.config.php (gitignored):',
            self::configSnippet($hostName),
            '',
            '4. Push, then install — or go live in one step:',
            '   php pinoox pinroll:push ' . $hostName,
            '   php pinoox pinroll:install ' . $hostName,
            '   Or: php pinoox pinroll:deploy ' . $hostName,
        ];
    }

    public static function configSnippet(string $hostName): string
    {
        unset($hostName);

        return implode("\n", [
            "'gate' => [",
            "    'site' => 'https://pinoox.com',",
            "    'token' => '<shared-host-token>',",
            '],',
        ]);
    }

    public static function redactToken(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return '';
        }

        $len = strlen($token);
        if ($len <= 4) {
            return str_repeat('•', $len);
        }

        return str_repeat('•', $len - 4) . substr($token, -4);
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
        $web = HostDir::webPathFromHost($host);

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
     * @return array{url: string, token: string, site: string}
     */
    private static function readGateArray(mixed $gate): array
    {
        if (!is_array($gate)) {
            return ['url' => '', 'token' => '', 'site' => ''];
        }

        $site = trim((string) ($gate['site'] ?? ''));
        $url = trim((string) ($gate['url'] ?? $gate['gate_url'] ?? ''));
        $token = trim((string) ($gate['token'] ?? ''));

        return self::normalizeCredentials($url, $token, $site);
    }

    /**
     * @return array{url: string, token: string, site: string}
     */
    private static function normalizeCredentials(string $url, string $token, string $site): array
    {
        $source = $site !== '' ? $site : $url;
        $expanded = GateUrl::expandOrEmpty($source);
        $origin = $site !== '' ? GateUrl::siteFrom($site) : GateUrl::siteFrom($url !== '' ? $url : $expanded);

        return [
            'url' => $expanded,
            'token' => $token,
            'site' => $origin,
        ];
    }
}
