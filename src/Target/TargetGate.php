<?php

namespace Pinoox\Pinroll\Target;

use Pinoox\Pinroll\Host\HostGate;

/**
 * @deprecated Use HostGate
 */
final class TargetGate
{
    public const EXAMPLE_DOMAIN = HostGate::EXAMPLE_DOMAIN;
    public const EXAMPLE_DIR = HostGate::EXAMPLE_DIR;

    /**
     * @param array<string, mixed> $host
     * @return array{url: string, token: string}
     */
    public static function credentials(array $host, ?string $via = null): array
    {
        return HostGate::credentials($host, $via);
    }

    /**
     * @param array<string, mixed> $host
     */
    public static function isConfigured(array $host, ?string $via = null): bool
    {
        return HostGate::isConfigured($host, $via);
    }

    /**
     * @return list<string>
     */
    public static function setupGuide(string $hostName, ?string $via = 'ftp'): array
    {
        return HostGate::setupGuide($hostName, $via);
    }

    public static function configSnippet(string $hostName): string
    {
        return HostGate::configSnippet($hostName);
    }

    public static function exampleUrl(?string $dir = null): string
    {
        return HostGate::exampleUrl($dir);
    }

    /**
     * @param array<string, mixed> $host
     */
    public static function suggestedUrl(array $host): string
    {
        return HostGate::suggestedUrl($host);
    }

    /**
     * @param array<string, mixed> $host
     */
    public static function ftpHost(array $host): string
    {
        return HostGate::ftpHost($host);
    }
}
