<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\HostDir;
use Pinoox\Pinroll\Support\PushProgress;
use Pinoox\Pinroll\Support\SyncPathValidator;
use Pinoox\Pinroll\Transport\FtpUploader;

/**
 * Mirror a local directory to the host over FTP (relative to deploy root).
 */
final class PathSyncer
{
    /** @var list<string> */
    private const SKIP_DIR_NAMES = [
        '.git',
        'node_modules',
        '.github',
        '.idea',
    ];

    /**
     * @return array{
     *     host: string,
     *     local: string,
     *     remote: string,
     *     files: int
     * }
     */
    public function sync(
        string $hostName,
        string $localPath,
        string $remoteRelative,
        ?string $projectRoot = null,
        bool $dryRun = false,
    ): array {
        $root = $projectRoot ?? Pinroll::paths()->root();
        $local = SyncPathValidator::localDir($localPath, $root);
        $remoteRelative = SyncPathValidator::remoteRelative($remoteRelative);

        $raw = Pinroll::hosts()->raw($hostName);
        $resolved = Pinroll::hosts()->resolve($hostName);
        $transport = (string) ($resolved['transport'] ?? 'ftp');

        if ($transport !== 'ftp') {
            throw new PinrollException(
                'pinroll:sync currently supports FTP hosts only (via=ftp). Host transport: ' . $transport,
            );
        }

        $deployRoot = HostDir::deployRoot(HostDir::fromTarget($resolved));
        $remoteBase = $deployRoot === '.'
            ? $remoteRelative
            : rtrim($deployRoot, '/') . '/' . $remoteRelative;

        if ($dryRun) {
            $files = $this->countLocalFiles($local);

            return [
                'host' => $hostName,
                'local' => $local,
                'remote' => $remoteBase,
                'files' => $files,
            ];
        }

        GateMaintainer::ensureBeforeDeploy($hostName, $resolved, $raw);

        $uploader = new FtpUploader();
        $connection = $uploader->connect(
            (string) ($resolved['host'] ?? ''),
            (string) ($resolved['user'] ?? ''),
            (string) ($resolved['password'] ?? ''),
        );

        try {
            PushProgress::arrow('FTP sync ' . basename($local) . ' → ' . $remoteBase);
            $files = $this->uploadFiltered($uploader, $connection, $local, $remoteBase);
            PushProgress::detail('Synced ' . $files . ' file(s)');
        } finally {
            if (is_resource($connection)) {
                @ftp_close($connection);
            }
        }

        return [
            'host' => $hostName,
            'local' => $local,
            'remote' => $remoteBase,
            'files' => $files,
        ];
    }

    /**
     * @param resource $connection
     */
    private function uploadFiltered(FtpUploader $uploader, $connection, string $localDir, string $remoteDir): int
    {
        $localDir = rtrim(str_replace('\\', '/', $localDir), '/');
        $remoteDir = rtrim(str_replace('\\', '/', $remoteDir), '/');
        $files = $this->collectFiles($localDir);
        $total = count($files);

        if ($total === 0) {
            throw new PinrollException('Nothing to sync — local directory has no files: ' . $localDir);
        }

        $uploader->mkdirRecursive($connection, $remoteDir);

        $current = 0;
        foreach ($files as $relative) {
            $current++;
            $local = $localDir . '/' . $relative;
            $remote = $remoteDir . '/' . $relative;
            $uploader->uploadFile($connection, $local, $remote);
            PushProgress::progress($current, $total, 'sync');
        }

        return $total;
    }

    private function countLocalFiles(string $localDir): int
    {
        return count($this->collectFiles($localDir));
    }

    /**
     * @return list<string>
     */
    private function collectFiles(string $localDir): array
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
