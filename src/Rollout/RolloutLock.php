<?php

namespace Pinoox\Pinroll\Rollout;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Support\Config;

final class RolloutLock
{
    public function __construct(private readonly Config $config)
    {
    }

    public function acquire(string $deployId): void
    {
        $path = $this->path();

        if (is_file($path)) {
            $existing = json_decode((string) file_get_contents($path), true);
            $started = (int) ($existing['started_at'] ?? 0);
            $timeout = (int) $this->config->get('lock_timeout', 3600);

            if ($started > 0 && (time() - $started) < $timeout) {
                throw new PinrollException('Another rollout is in progress.');
            }

            $this->release();
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, json_encode([
            'deploy_id' => $deployId,
            'pid' => getmypid(),
            'started_at' => time(),
        ], JSON_THROW_ON_ERROR), LOCK_EX);
    }

    public function release(): void
    {
        $path = $this->path();
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function path(): string
    {
        return $this->config->storage((string) $this->config->get('lock_file', 'pinroll/deploy.lock'));
    }
}
