<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Support\ProjectPaths;
use ZipArchive;

/**
 * Build a sync zip whose entries are rooted at a remote-relative target path.
 */
final class PathArchiveBuilder
{
    /** @var list<string> */
    private const SKIP_DIR_NAMES = [
        '.git',
        'node_modules',
        '.github',
        '.idea',
    ];

    /**
     * @return array{zip: string, files: int, deploy_id: string, bytes: int}
     */
    public function build(string $localDir, string $remoteRelative, string $projectRoot): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new PinrollException('ZipArchive is not available. Install the PHP zip extension.');
        }

        $localDir = rtrim(str_replace('\\', '/', $localDir), '/');
        $remoteRelative = trim(str_replace('\\', '/', $remoteRelative), '/');
        $files = $this->collectFiles($localDir);
        if ($files === []) {
            throw new PinrollException('Nothing to sync — local directory has no files: ' . $localDir);
        }

        $workDir = ProjectPaths::workDir(new \Pinoox\Pinroll\Support\NativePathResolver($projectRoot));
        if (!is_dir($workDir) && !mkdir($workDir, 0755, true) && !is_dir($workDir)) {
            throw new PinrollException('Unable to create Pinroll work directory: ' . $workDir);
        }

        $deployId = 'sync_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
        $zipPath = $workDir . '/sync-' . $deployId . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new PinrollException('Unable to create sync zip: ' . $zipPath);
        }

        $zip->addEmptyDir($remoteRelative);
        foreach ($files as $relative) {
            $local = $localDir . '/' . $relative;
            $nameInZip = $remoteRelative . '/' . $relative;
            if (!$zip->addFile($local, $nameInZip)) {
                $zip->close();
                @unlink($zipPath);
                throw new PinrollException('Unable to add file to sync zip: ' . $relative);
            }
        }

        $zip->close();

        return [
            'zip' => $zipPath,
            'files' => count($files),
            'deploy_id' => $deployId,
            'bytes' => (int) filesize($zipPath),
        ];
    }

    /**
     * @return list<string>
     */
    public function collectFiles(string $localDir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($localDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($localDir) + 1));
            if ($this->shouldSkipRelative($relative)) {
                continue;
            }

            $files[] = $relative;
        }

        return $files;
    }

    private function shouldSkipRelative(string $relative): bool
    {
        $parts = explode('/', str_replace('\\', '/', $relative));
        foreach ($parts as $part) {
            if (in_array($part, self::SKIP_DIR_NAMES, true)) {
                return true;
            }
        }

        return false;
    }
}
