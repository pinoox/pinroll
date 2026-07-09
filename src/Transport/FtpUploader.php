<?php

namespace Pinoox\Pinroll\Transport;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Support\HostDir;
use Pinoox\Pinroll\Support\PushProgress;

final class FtpUploader
{
    /**
     * @param resource $connection
     */
    public function connect(string $host, string $user, string $password, int $timeout = 20)
    {
        if (!function_exists('ftp_connect')) {
            throw new PinrollException('FTP extension is not available.');
        }

        PushProgress::arrow('FTP connecting to ' . $host . '…');
        $connection = @ftp_connect($host, 21, $timeout);
        if ($connection === false) {
            throw new PinrollException(
                'FTP connection failed (timeout ' . $timeout . 's). Check PINROLL_*_HOST and network.',
            );
        }

        if (function_exists('ftp_set_option')) {
            @ftp_set_option($connection, FTP_TIMEOUT_SEC, $timeout);
        }

        if (!@ftp_login($connection, $user, $password)) {
            ftp_close($connection);
            throw new PinrollException('FTP login failed. Check PINROLL_*_USER / PASSWORD.');
        }

        ftp_pasv($connection, true);
        PushProgress::arrow('FTP connected');

        return $connection;
    }

    /**
     * @param resource $connection
     */
    public function uploadFile($connection, string $localFile, string $remoteFile): void
    {
        $remoteDir = dirname(str_replace('\\', '/', $remoteFile));
        $this->mkdirRecursive($connection, $remoteDir);

        if (!@ftp_put($connection, $remoteFile, $localFile, FTP_BINARY)) {
            throw new PinrollException('FTP upload failed: ' . $remoteFile);
        }
    }

    /**
     * @param resource $connection
     */
    public function mkdirRecursive($connection, string $path): void
    {
        $parts = array_filter(explode('/', str_replace('\\', '/', $path)));
        $current = '';

        foreach ($parts as $part) {
            $current = $current === '' ? $part : $current . '/' . $part;
            @ftp_mkdir($connection, $current);
        }
    }

    /**
     * @param array<string, mixed> $target
     */
    public function deployRoot(array $target): string
    {
        $dir = HostDir::fromTarget($target);

        return $dir === '' ? '.' : $dir;
    }

    /**
     * @param resource $connection
     */
    public function uploadDirectory($connection, string $localDir, string $remoteDir, ?string $label = null): int
    {
        if (!is_dir($localDir)) {
            throw new PinrollException('Local directory not found: ' . $localDir);
        }

        $localDir = rtrim(str_replace('\\', '/', $localDir), '/');
        $remoteDir = rtrim(str_replace('\\', '/', $remoteDir), '/');
        $files = $this->collectFiles($localDir);
        $total = count($files);

        if ($total === 0) {
            return 0;
        }

        $this->mkdirRecursive($connection, $remoteDir);

        $current = 0;
        foreach ($files as $relative) {
            $current++;
            $local = $localDir . '/' . $relative;
            $remote = $remoteDir . '/' . $relative;
            $this->uploadFile($connection, $local, $remote);
            \Pinoox\Pinroll\Support\PushProgress::progress($current, $total, $label ?? 'gate');
        }

        return $total;
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

            $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($localDir) + 1));
        }

        return $files;
    }
}
