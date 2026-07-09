<?php

namespace Pinoox\Pinroll\Transport;

use Pinoox\Pinroll\Contract\TransportInterface;
use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Release\ReleaseManifest;
use Pinoox\Pinroll\Rollout\RolloutSession;
use Pinoox\Pinroll\Support\Config;

final class TransportResolver
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @param array<string, mixed> $target
     */
    public function resolve(array $target): TransportInterface
    {
        $name = (string) ($target['transport'] ?? $this->config->get('default_transport', 'pinion'));

        return match ($name) {
            'pinion' => new PinionTransport($this->config),
            'ssh' => new SshTransport($this->config),
            'ftp' => new FtpTransport($this->config),
            'local' => new LocalTransport($this->config),
            default => throw new PinrollException("Unknown transport: {$name}"),
        };
    }
}
