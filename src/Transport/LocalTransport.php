<?php

namespace Pinoox\Pinroll\Transport;

use Pinoox\Pinroll\Contract\TransportInterface;
use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Release\ReleaseManifest;
use Pinoox\Pinroll\Rollout\RolloutSession;
use Pinoox\Pinroll\Support\Config;

final class LocalTransport implements TransportInterface
{
    public function __construct(private readonly Config $config)
    {
    }

    public function name(): string
    {
        return 'local';
    }

    public function send(string $archivePath, ReleaseManifest $manifest, array $target, RolloutSession $session): void
    {
        $dest = (string) ($target['path'] ?? $this->config->get('incoming_path', 'pinroll/incoming'));
        $dest = $this->resolveDestination($dest);

        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        $targetFile = rtrim($dest, '/') . '/' . basename($archivePath);
        if (!copy($archivePath, $targetFile)) {
            throw new PinrollException('Local copy failed.');
        }

        $manifestPath = rtrim($dest, '/') . '/' . $manifest->deployId() . '.manifest.json';
        $manifest->write($manifestPath);

        $session->addStep('transport', 'ok', 'Archive copied locally to ' . $targetFile);
    }

    private function resolveDestination(string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        if (str_starts_with($path, 'storage/')) {
            return $this->config->paths()->root() . '/' . ltrim($path, '/');
        }

        return $this->config->storage(ltrim($path, '/'));
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
    }
}
