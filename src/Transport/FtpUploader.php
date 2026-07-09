<?php

namespace Pinoox\Pinroll\Transport;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Support\HostDir;

final class FtpUploader
{
    /**
     * @param resource $connection
     */
    public function connect(string $host, string $user, string $password)
    {
        if (!function_exists('ftp_connect')) {
            throw new PinrollException('FTP extension is not available.');
        }

        $connection = @ftp_connect($host);
        if ($connection === false) {
            throw new PinrollException('FTP connection failed.');
        }

        if (!@ftp_login($connection, $user, $password)) {
            ftp_close($connection);
            throw new PinrollException('FTP login failed.');
        }

        ftp_pasv($connection, true);

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
            \Pinoox\Pinroll\Support\PushProgress::progress($current, $total, $label ?? $relative);
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
