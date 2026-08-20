<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Support\SyncPathValidator;
use ZipArchive;

/**
 * Extract a path-sync zip under the platform root (same rules as pingate POST ?route=sync).
 */
final class SyncPathExtractor
{
    /**
     * @param array<string, mixed> $input
     * @return array{target: string, zip: string, deleted_zip: bool, files: int}
     */
    public function extract(string $root, array $input): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $target = SyncPathValidator::remoteRelative((string) ($input['target'] ?? ''));

        $incoming = $root . '/storage/pinroll/incoming';
        $deployId = trim((string) ($input['deploy_id'] ?? $input['filename'] ?? ''));
        $zipPath = $this->resolveZip($incoming, $deployId);

        $zipReal = realpath($zipPath);
        if ($zipReal === false || !is_file($zipReal)) {
            throw new PinrollException('Sync zip not found in storage/pinroll/incoming.');
        }
        $zipReal = str_replace('\\', '/', $zipReal);

        if (!class_exists(ZipArchive::class)) {
            throw new PinrollException('ZipArchive is not available on the host.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipReal) !== true) {
            throw new PinrollException('Unable to open sync zip.');
        }

        try {
            $this->assertSafe($zip, $target);

            $targetDir = $root . '/' . $target;
            $backupDir = $root . '/storage/tmp/sync-bak-' . bin2hex(random_bytes(4));
            $hadTarget = is_dir($targetDir);
            if ($hadTarget) {
                if (!is_dir(dirname($backupDir))) {
                    mkdir(dirname($backupDir), 0755, true);
                }
                if (!@rename($targetDir, $backupDir)) {
                    throw new PinrollException('Unable to move existing target aside before extract.');
                }
            }

            try {
                $this->extractSafe($zip, $root, $target);
            } catch (\Throwable $e) {
                if (is_dir($targetDir)) {
                    $this->removeDirectory($targetDir);
                }
                if ($hadTarget && is_dir($backupDir)) {
                    @rename($backupDir, $targetDir);
                }
                throw $e;
            }

            if ($hadTarget && is_dir($backupDir)) {
                $this->removeDirectory($backupDir);
            }
        } finally {
            $zip->close();
        }

        $deleteZip = !array_key_exists('delete_zip', $input) || !empty($input['delete_zip']);
        $deletedZip = false;
        if ($deleteZip) {
            $deletedZip = @unlink($zipReal);
        }

        return [
            'target' => $target,
            'zip' => basename($zipReal),
            'deleted_zip' => (bool) $deletedZip,
            'files' => $this->countFiles($root . '/' . $target),
        ];
    }

    private function resolveZip(string $incoming, string $deployId): string
    {
        $incoming = rtrim(str_replace('\\', '/', $incoming), '/');
        if (!is_dir($incoming)) {
            throw new PinrollException('No sync archive found (incoming missing).');
        }

        if ($deployId !== '') {
            foreach ([
                $incoming . '/' . $deployId,
                $incoming . '/' . $deployId . '.zip',
                $incoming . '/sync-' . $deployId . '.zip',
            ] as $path) {
                if (is_file($path)) {
                    return $path;
                }
            }

            throw new PinrollException('Sync zip not found for deploy_id: ' . $deployId);
        }

        throw new PinrollException('deploy_id is required for path sync.');
    }

    private function assertSafe(ZipArchive $zip, string $target): void
    {
        $prefix = $target . '/';
        $count = $zip->numFiles;
        if ($count < 1 || $count > 80000) {
            throw new PinrollException('Sync zip has an invalid entry count.');
        }

        $total = 0;
        $hasFile = false;
        for ($i = 0; $i < $count; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                throw new PinrollException('Unable to read zip entry metadata.');
            }
            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
            if ($name === '' || str_contains($name, '..') || str_contains($name, "\0") || $name[0] === '/') {
                throw new PinrollException('Unsafe zip entry: ' . $name);
            }
            if ($name !== $target && $name !== $prefix && !str_starts_with($name, $prefix)) {
                throw new PinrollException('Zip may only contain paths under ' . $target . '/.');
            }
            $total += (int) ($stat['size'] ?? 0);
            if ($total > 500 * 1024 * 1024) {
                throw new PinrollException('Sync zip uncompressed size exceeds limit.');
            }
            if (!str_ends_with($name, '/')) {
                $hasFile = true;
            }
        }

        if (!$hasFile) {
            throw new PinrollException('Sync zip has no files.');
        }
    }

    private function extractSafe(ZipArchive $zip, string $root, string $target): void
    {
        $prefix = $target . '/';
        $allowed = $root . '/' . $target;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if ($name === '' || str_ends_with($name, '/')) {
                $dir = $root . '/' . rtrim($name, '/');
                if ($name !== '' && !is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                    throw new PinrollException('Unable to create directory for zip entry.');
                }
                continue;
            }

            if (!str_starts_with($name, $prefix) && $name !== $target) {
                throw new PinrollException('Refusing extract outside target: ' . $name);
            }

            $dest = $root . '/' . $name;
            $normalized = str_replace('\\', '/', $dest);
            if ($normalized !== $allowed && !str_starts_with($normalized, $allowed . '/')) {
                throw new PinrollException('Refusing extract outside target root.');
            }

            $parent = dirname($dest);
            if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
                throw new PinrollException('Unable to create parent directory.');
            }

            $contents = $zip->getFromIndex($i);
            if ($contents === false || file_put_contents($dest, $contents) === false) {
                throw new PinrollException('Unable to write extracted file.');
            }
        }
    }

    private function countFiles(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }

        return $count;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
