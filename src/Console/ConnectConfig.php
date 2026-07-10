<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Host\HostGate;
use Pinoox\Pinroll\Support\HostDir;

/**
 * Detect whether pinroll:connect setup is complete for a host.
 */
final class ConnectConfig
{
    public static function isConfigured(array $resolved, string $via): bool
    {
        $via = strtolower(trim($via));
        if ($via === '') {
            $via = 'ftp';
        }

        if (HostDir::fromHost($resolved) === '') {
            return false;
        }

        $gateUrl = trim((string) ($resolved['gate_url'] ?? ''));
        if ($gateUrl === '') {
            $gateUrl = HostGate::credentials($resolved)['url'];
        }

        if ($gateUrl === '') {
            return false;
        }

        return match ($via) {
            'ftp' => self::hasCredentials($resolved, 'host', 'user'),
            'ssh' => self::hasCredentials($resolved, 'host', 'user'),
            'pinion' => true,
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $resolved
     */
    private static function hasCredentials(array $resolved, string $hostKey, string $userKey): bool
    {
        return trim((string) ($resolved[$hostKey] ?? '')) !== ''
            && trim((string) ($resolved[$userKey] ?? '')) !== '';
    }
}
