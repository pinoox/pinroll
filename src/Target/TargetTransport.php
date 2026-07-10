<?php

namespace Pinoox\Pinroll\Target;

use Pinoox\Pinroll\Host\HostTransport;

/**
 * @deprecated Use HostTransport
 */
final class TargetTransport
{
    /**
     * @param array<string, mixed> $host
     * @return list<string>
     */
    public static function names(array $host): array
    {
        return HostTransport::names($host);
    }

    /**
     * @param array<string, mixed> $host
     * @return array<string, mixed>
     */
    public static function resolve(array $host, ?string $via = null): array
    {
        return HostTransport::resolve($host, $via);
    }
}
