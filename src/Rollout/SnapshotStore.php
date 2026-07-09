<?php

namespace Pinoox\Pinroll\Rollout;

use Pinoox\Pinroll\Release\ReleaseManifest;
use Pinoox\Pinroll\Support\Config;

final class SnapshotStore
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @return array{path: string, files: list<array{path: string, checksum: string}>}
     */
    public function capture(string $deployId, array $paths): array
    {
        $snapshotDir = $this->config->storage('backups/' . $deployId);
        if (!is_dir($snapshotDir)) {
            mkdir($snapshotDir, 0755, true);
        }

        $index = [];
        foreach ($paths as $relative) {
            $source = $this->resolvePath($relative);
            if (!is_file($source)) {
                continue;
            }

            $dest = $snapshotDir . '/' . ltrim($relative, '/');
            $destDir = dirname($dest);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }

            copy($source, $dest);
            $index[] = [
                'path' => $relative,
                'checksum' => hash_file('sha256', $source),
            ];
        }

        file_put_contents($snapshotDir . '/index.json', json_encode($index, JSON_PRETTY_PRINT));

        return ['path' => $snapshotDir, 'files' => $index];
    }

    public function restore(string $deployId): void
    {
        $snapshotDir = $this->config->storage('backups/' . $deployId);
        $indexFile = $snapshotDir . '/index.json';

        if (!is_file($indexFile)) {
            return;
        }

        $index = json_decode((string) file_get_contents($indexFile), true);
        if (!is_array($index)) {
            return;
        }

        foreach ($index as $entry) {
            $relative = (string) ($entry['path'] ?? '');
            $source = $snapshotDir . '/' . ltrim($relative, '/');
            $dest = $this->resolvePath($relative);

            if (!is_file($source)) {
                continue;
            }

            $destDir = dirname($dest);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }

            copy($source, $dest);
        }
    }

    private function resolvePath(string $relative): string
    {
        $root = defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd();

        return rtrim((string) $root, '/') . '/' . ltrim($relative, '/');
    }
}
