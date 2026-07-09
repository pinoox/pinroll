<?php

namespace Pinoox\Pinroll\Support;

final class HostDir
{
    public const GATE_ENTRY = 'pingate.php';
    public const GATE_DIR = 'gate';

    public static function normalize(?string $hostDir): string
    {
        $hostDir = trim(str_replace('\\', '/', (string) $hostDir), '/');

        if ($hostDir === '' || $hostDir === '.') {
            return '';
        }

        if (str_contains($hostDir, '..')) {
            return '';
        }

        return $hostDir;
    }

    public static function suggestFromDomain(string $domain): string
    {
        $domain = trim($domain);
        $domain = (string) preg_replace('#^https?://#i', '', $domain);
        $domain = strtolower(rtrim($domain, '/'));
        $host = (string) strtok($domain, '/');
        $parts = array_values(array_filter(explode('.', $host)));

        if ($parts === []) {
            return '';
        }

        if (count($parts) === 1) {
            return preg_replace('/[^a-z0-9_-]/', '', $parts[0]) ?: '';
        }

        $tld = $parts[count($parts) - 1];
        $secondLevelTlds = ['co', 'com', 'org', 'net', 'ac', 'gov'];
        $isMultiPartTld = count($parts) >= 3 && in_array($parts[count($parts) - 2], $secondLevelTlds, true);

        if ($isMultiPartTld && count($parts) >= 3) {
            return preg_replace('/[^a-z0-9_-]/', '', $parts[count($parts) - 3]) ?: '';
        }

        if (count($parts) === 2) {
            return preg_replace('/[^a-z0-9_-]/', '', $parts[0]) ?: '';
        }

        return preg_replace('/[^a-z0-9_-]/', '', $parts[0]) ?: '';
    }

    public static function publicHtmlPath(?string $hostDir): string
    {
        $hostDir = self::normalize($hostDir);

        return $hostDir === '' ? 'public_html' : 'public_html/' . $hostDir;
    }

    /**
     * Web path to pingate.php on the host (depends on target dir).
     */
    public static function gateEntryWebPath(?string $hostDir = null): string
    {
        $hostDir = self::normalize($hostDir);

        return $hostDir === '' ? self::GATE_ENTRY : $hostDir . '/' . self::GATE_ENTRY;
    }

    /**
     * Example host path for guides, e.g. public_html/pinoox3/pingate.php
     */
    public static function gateEntryPath(?string $hostDir = null): string
    {
        return self::publicHtmlPath($hostDir) . '/' . self::GATE_ENTRY;
    }

  /**
     * Example host path to gate config folder.
     */
    public static function pinGatePath(?string $hostDir): string
    {
        return self::publicHtmlPath($hostDir) . '/' . self::GATE_DIR;
    }

    public static function localEntryPath(): string
    {
        return 'pinroll/' . self::GATE_ENTRY;
    }

    public static function localGateDir(): string
    {
        return 'pinroll/' . self::GATE_DIR;
    }

    public static function localHtaccessSnippet(): string
    {
        return 'pinroll/htaccess.snippet';
    }

    public static function gateUrlFromDomain(string $domain, ?string $hostDir = null): string
    {
        $domain = trim($domain);
        $domain = (string) preg_replace('#^https?://#i', '', $domain);
        $domain = rtrim($domain, '/');

        if ($domain === '') {
            $domain = 'pinoox.com';
        }

        return 'https://' . $domain . '/' . self::gateEntryWebPath($hostDir) . '?route=';
    }

    /**
     * @param array<string, mixed> $target
     */
    public static function fromTarget(array $target): string
    {
        $value = $target['dir'] ?? $target['host_dir'] ?? $target['install'] ?? '';

        return self::normalize((string) $value);
    }

    public static function dirFromGateUrl(?string $gateUrl): string
    {
        $gateUrl = trim((string) $gateUrl);
        if ($gateUrl === '') {
            return '';
        }

        $path = parse_url($gateUrl, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '';
        }

        $path = trim($path, '/');
        $suffix = '/' . self::GATE_ENTRY;

        if ($path === self::GATE_ENTRY) {
            return '';
        }

        if (str_ends_with($path, $suffix)) {
            return self::normalize(substr($path, 0, -strlen($suffix)));
        }

        return '';
    }

    public static function incomingDir(?string $hostDir): string
    {
        $hostDir = self::normalize($hostDir);

        return ($hostDir === '' ? '' : $hostDir . '/') . 'storage/pinroll/incoming';
    }

    /**
     * Remote deploy root relative to FTP/SSH login (document root).
     * '' / '.' = site root; 'pinoox3' = subdirectory. Never prefixes public_html/.
     */
    public static function deployRoot(?string $hostDir): string
    {
        $hostDir = self::normalize($hostDir);

        return $hostDir === '' ? '.' : $hostDir;
    }

    /**
     * Human-readable extract path for guides (cPanel usually under public_html/).
     */
    public static function extractGuidePath(?string $hostDir): string
    {
        $deploy = self::deployRoot($hostDir);

        if ($deploy === '.') {
            return 'FTP/SSH login root (usually public_html/)';
        }

        return $deploy . '/ (usually public_html/' . $deploy . '/)';
    }

    public static function sshIncomingPath(?string $hostDir, string $filename): string
    {
        return self::incomingDir($hostDir) . '/' . ltrim($filename, '/');
    }

    /**
     * @param array<string, mixed> $target
     */
    public static function incomingFromTarget(array $target, string $filename = ''): string
    {
        $custom = (string) ($target['remote_dir'] ?? $target['remote_path'] ?? '');
        if ($custom !== '') {
            return $filename !== '' ? rtrim($custom, '/') . '/' . ltrim($filename, '/') : $custom;
        }

        $dir = self::incomingDir(self::fromTarget($target));

        return $filename !== '' ? $dir . '/' . ltrim($filename, '/') : $dir;
    }

    /**
     * @return list<string>
     */
    public static function transportGuide(string $transport, ?string $hostDir): array
    {
        $incoming = self::incomingDir($hostDir);
        $guideDeploy = self::extractGuidePath($hostDir);

        return match ($transport) {
            'pinion' => self::deployGuide($hostDir),
            'ssh', 'ftp' => [
                'Upload via ' . $transport . ' to: ' . $incoming . '/',
                'For push -a: add top-level gate { url, token } (run pinroll:gate).',
                'Deploy root: ' . $guideDeploy,
            ],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    public static function deployGuide(?string $hostDir): array
    {
        $guideDeploy = self::extractGuidePath($hostDir);

        return [
            '1. Deploy full Pinoox platform to ' . $guideDeploy . ' (vendor/, apps/, composer install — first time only).',
            '2. Run php pinoox pinroll:gate (FTP uploads pingate.php + gate/; or -z for a zip).',
            '3. Set gate { url, token } in pinroll.config.php / .env, then run pinroll:check.',
        ];
    }
}
