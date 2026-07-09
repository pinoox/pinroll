<?php

namespace Pinoox\Pinroll\PinGate;

use Pinoox\Pinroll\Support\Config;

final class PinGateRouter
{
    public function __construct(private readonly Config $config)
    {
    }

    public function basePath(): string
    {
        return (string) $this->config->get('gate_path', '_pinoox/gate');
    }

    /**
     * @return list<string>
     */
    public function routes(): array
    {
        return [
            'POST /push/init',
            'POST /push/upload',
            'POST /push/complete',
            'POST /apply',
            'GET /status',
            'GET /incoming',
            'POST /rollback',
            'POST /cleanup',
            'GET /history',
        ];
    }
}
