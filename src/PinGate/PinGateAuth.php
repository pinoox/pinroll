<?php

namespace Pinoox\Pinroll\PinGate;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Support\Config;
use Pinoox\Pinroll\Support\TokenGenerator;

final class PinGateAuth
{
    public function __construct(private readonly Config $config)
    {
    }

    public function issueToken(): string
    {
        return TokenGenerator::token();
    }

    public function verifyBearer(?string $authorization, string $expectedHash): void
    {
        $token = $this->extractBearer($authorization);
        if ($token === '') {
            throw new PinrollException('Missing bearer token.', 401);
        }

        if (!hash_equals($expectedHash, TokenGenerator::hashToken($token))) {
            $this->registerFailure();
            throw new PinrollException('Invalid token.', 401);
        }
    }

    public function hashToken(string $token): string
    {
        return TokenGenerator::hashToken($token);
    }

    private function extractBearer(?string $authorization): string
    {
        if ($authorization === null || !str_starts_with($authorization, 'Bearer ')) {
            return '';
        }

        return trim(substr($authorization, 7));
    }

    private function registerFailure(): void
    {
        $dir = $this->config->storage('gate/rate-limit');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $file = $dir . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $ip) . '.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : ['count' => 0, 'blocked_until' => 0];
        $data['count'] = (int) ($data['count'] ?? 0) + 1;

        if ($data['count'] >= 3) {
            $data['blocked_until'] = time() + 3600;
        }

        file_put_contents($file, json_encode($data));
    }
}
