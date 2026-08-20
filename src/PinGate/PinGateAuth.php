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

    /**
     * @param array<string, mixed> $gate
     */
    public function verifyBearerForHost(?string $authorization, string $root, array $gate = []): void
    {
        $token = $this->extractBearer($authorization);
        if ($token === '') {
            throw new PinrollException('Missing bearer token.', 401);
        }

        if (!GateTokenRegistry::verifyPlainToken($token, $root, $gate)) {
            $this->registerFailure();
            throw new PinrollException('Invalid token.', 401);
        }
    }

    /**
     * @param array<string, mixed> $gate
     * @return list<string>
     */
    public function acceptedHashes(string $root, array $gate = []): array
    {
        return GateTokenRegistry::acceptedHashes($root, $gate);
    }

    /**
     * @param array<string, mixed> $gate
     */
    public function expectedHash(array $gate, string $root = ''): string
    {
        $envToken = $this->envToken($root);
        if ($envToken !== '') {
            return TokenGenerator::hashToken($envToken);
        }

        return (string) ($gate['token_hash'] ?? '');
    }

    public function hashToken(string $token): string
    {
        return TokenGenerator::hashToken($token);
    }

    private function envToken(string $root): string
    {
        $fromEnv = $_ENV['PINROLL_GATE_TOKEN'] ?? $_SERVER['PINROLL_GATE_TOKEN'] ?? getenv('PINROLL_GATE_TOKEN');
        if (is_string($fromEnv) && preg_match('/^[a-f0-9]{64}$/', trim($fromEnv))) {
            return trim($fromEnv);
        }

        $file = rtrim(str_replace('\\', '/', $root), '/') . '/.env';
        if ($root === '' || !is_file($file)) {
            return '';
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^PINROLL_GATE_TOKEN\s*=\s*(.*)$/', $line, $matches) !== 1) {
                continue;
            }

            $token = trim(trim((string) $matches[1]), "\"'");
            if (preg_match('/^[a-f0-9]{64}$/', $token)) {
                return $token;
            }
        }

        return '';
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
