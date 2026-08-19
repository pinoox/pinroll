<?php

namespace Pinoox\Pinroll\Host;

use Pinoox\Pinroll\Console\GateUrl;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\HostDir;

/**
 * Resolved host view after library → overlay → HostEnv merge (secrets redacted).
 */
final class ResolvedConfig
{
    /**
     * @return array<string, mixed>
     */
    public static function forHost(string $name, ?string $via = null): array
    {
        $raw = Pinroll::hosts()->raw($name);
        $resolved = Pinroll::hosts()->resolve($name, $via);
        $gate = HostGate::credentials($raw, $via);
        $site = $gate['site'] !== '' ? $gate['site'] : GateUrl::siteFrom($gate['url']);

        return [
            'host' => $name,
            'via' => (string) ($resolved['via'] ?? $resolved['transport'] ?? ''),
            'deploy_path' => HostDir::fromHost($resolved),
            'web_path' => HostDir::webPathFromHost(array_key_exists('web_path', $raw) ? $raw : $resolved),
            'site' => $site,
            'gate_url' => $gate['url'],
            'token_redacted' => HostGate::redactToken($gate['token']),
            'token_set' => $gate['token'] !== '',
            'ftp_host' => trim((string) ($resolved['host'] ?? HostGate::ftpHost($raw))),
            'ftp_user' => trim((string) ($resolved['user'] ?? '')),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $rows = [];
        foreach (Pinroll::hosts()->names() as $name) {
            $rows[] = self::forHost($name);
        }

        return $rows;
    }
}
