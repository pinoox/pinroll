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

        if (!@ftp_pasv($connection, true)) {
            PushProgress::detail('FTP PASV failed — continuing in active mode');
        } else {
            PushProgress::arrow('FTP connected');
        }

        return $connection;
    }

    /**
     * @param resource $connection
     */
    public function uploadFile($connection, string $localFile, string $remoteFile, ?string $label = null): void
    {
        $remoteDir = dirname(str_replace('\\', '/', $remoteFile));
        $this->mkdirRecursive($connection, $remoteDir);

        $label = $label ?? basename($localFile);
        $size = is_file($localFile) ? (int) filesize($localFile) : 0;

        if (function_exists('ftp_nb_put')) {
            $this->uploadFileNonBlocking($connection, $localFile, $remoteFile, $size, $label);

            return;
        }

        if (!@ftp_put($connection, $remoteFile, $localFile, FTP_BINARY)) {
            throw new PinrollException('FTP upload failed: ' . $remoteFile);
        }

        if ($size > 0) {
            PushProgress::progress($size, $size, $label);
        }
    }

    /**
     * @param resource $connection
     */
    private function uploadFileNonBlocking($connection, string $localFile, string $remoteFile, int $size, string $label): void
    {
        $ret = @ftp_nb_put($connection, $remoteFile, $localFile, FTP_BINARY);
        if ($ret === FTP_FAILED) {
            if (!@ftp_put($connection, $remoteFile, $localFile, FTP_BINARY)) {
                throw new PinrollException('FTP upload failed: ' . $remoteFile);
            }
            if ($size > 0) {
                PushProgress::progress($size, $size, $label);
            }

            return;
        }

        while ($ret === FTP_MOREDATA) {
            PushProgress::pulse(
                $label . ($size > 0 ? '  ' . PushProgressBar::bytes($size) : ''),
            );
            $ret = ftp_nb_continue($connection);
        }

        if ($ret !== FTP_FINISHED) {
            throw new PinrollException('FTP upload failed: ' . $remoteFile);
        }

        if ($size > 0) {
            PushProgress::progress($size, $size, $label);
        } else {
            PushProgress::endBar();
        }
    }

    public function uploadFileCurl(string $host, string $user, string $password, string $localFile, string $remoteFile): void
    {
        if (!function_exists('curl_init')) {
            throw new PinrollException('FTP upload failed and cURL is not available for fallback.');
        }

        if (!is_file($localFile)) {
            throw new PinrollException('Missing local file for FTP upload: ' . $localFile);
        }

        $remoteFile = ltrim(str_replace('\\', '/', $remoteFile), '/');
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $remoteFile)));
        $url = 'ftp://' . $host . '/' . $encodedPath;

        $stream = fopen($localFile, 'rb');
        if ($stream === false) {
            throw new PinrollException('Unable to read local file for FTP upload: ' . $localFile);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            fclose($stream);
            throw new PinrollException('Unable to initialize cURL for FTP upload.');
        }

        $createDir = defined('CURLFTP_CREATE_DIR_RETRY') ? CURLFTP_CREATE_DIR_RETRY : 2;
        $label = basename($localFile);
        $size = (int) filesize($localFile);
        curl_setopt_array($ch, [
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $stream,
            CURLOPT_INFILESIZE => $size,
            CURLOPT_USERNAME => $user,
            CURLOPT_PASSWORD => $password,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_FTP_CREATE_MISSING_DIRS => $createDir,
            CURLOPT_FTP_USE_EPSV => false,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static function ($resource, $dltotal, $dlnow, $ultotal, $ulnow) use ($label, $size): int {
                unset($resource, $dltotal, $dlnow);
                $total = (int) $ultotal > 0 ? (int) $ultotal : $size;
                $now = (int) $ulnow;
                if ($total > 0 && $now > 0) {
                    PushProgress::progress($now, $total, $label);
                }

                return 0;
            },
        ]);

        $ok = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($stream);

        if ($ok === false) {
            throw new PinrollException('FTP (cURL) upload failed: ' . $error);
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
     * Remove leftover remote gate/ (legacy PinGate layout).
     *
     * @param resource $connection
     */
    public function removeRemoteTree($connection, string $remoteDir, bool $mustBeGate = true): void
    {
        $remoteDir = trim(str_replace('\\', '/', $remoteDir), '/');
        if ($remoteDir === '' || $remoteDir === '.') {
            return;
        }
        if ($mustBeGate && basename($remoteDir) !== HostDir::GATE_DIR) {
            return;
        }

        $list = @ftp_nlist($connection, $remoteDir);
        if ($list === false) {
            @ftp_delete($connection, $remoteDir);
            @ftp_rmdir($connection, $remoteDir);

            return;
        }

        if ($mustBeGate) {
            PushProgress::arrow('FTP remove leftover ' . $remoteDir . '/');
        }
        foreach ($list as $item) {
            $name = basename(str_replace('\\', '/', (string) $item));
            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }
            $path = $remoteDir . '/' . $name;
            if (@ftp_delete($connection, $path) === false) {
                $this->removeRemoteTree($connection, $path, false);
            }
        }

        @ftp_rmdir($connection, $remoteDir);
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
