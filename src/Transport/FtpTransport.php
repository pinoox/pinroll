<?php

namespace Pinoox\Pinroll\Transport;

use Pinoox\Pinroll\Contract\TransportInterface;
use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Release\ReleaseManifest;
use Pinoox\Pinroll\Rollout\RolloutSession;
use Pinoox\Pinroll\Support\Config;
use Pinoox\Pinroll\Support\HostDir;
use Pinoox\Pinroll\Support\IncomingRelease;
use Pinoox\Pinroll\Support\PushProgress;

final class FtpTransport implements TransportInterface
{
    public function __construct(private readonly Config $config)
    {
    }

    public function name(): string
    {
        return 'ftp';
    }

    public function send(string $archivePath, ReleaseManifest $manifest, array $target, RolloutSession $session): void
    {
        $host = (string) ($target['host'] ?? '');
        $user = (string) ($target['user'] ?? '');
        $password = (string) ($target['password'] ?? '');
        $remoteDir = HostDir::incomingFromTarget($target);

        if ($host === '' || $user === '') {
            throw new PinrollException('FTP target requires host and user.');
        }

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
        $this->ftpMkdirRecursive($connection, $remoteDir);

        // Upload .pinx (zip) for PinGate apply — not the .tar wrapper (PinxInstaller cannot open tar).
        [$localFile, $remoteName] = $this->resolveUploadPayload($archivePath, $manifest);
        $remoteFile = rtrim($remoteDir, '/') . '/' . $remoteName;
        $size = is_file($localFile) ? (int) filesize($localFile) : 0;
        PushProgress::arrow($remoteName . ($size > 0 ? ' (' . $this->formatBytes($size) . ')' : ''));

        if (!@ftp_put($connection, $remoteFile, $localFile, FTP_BINARY)) {
            ftp_close($connection);
            throw new PinrollException('FTP upload failed — check connection and remote path.');
        }

        $remoteSize = @ftp_size($connection, $remoteFile);
        if ($remoteSize > 0 && $size > 0 && (int) $remoteSize !== $size) {
            ftp_close($connection);
            throw new PinrollException(
                'FTP upload size mismatch (local ' . $size . ' vs remote ' . $remoteSize . '). Use binary mode / retry.',
            );
        }

        $session->addStep('transport', 'ok', 'Archive uploaded via FTP to ' . $remoteFile);

        if (($manifest->deploy()['vendor'] ?? false) === true) {
            $vendorLocal = rtrim($this->config->paths()->root(), '/') . '/vendor';
            if (!is_dir($vendorLocal)) {
                ftp_close($connection);
                throw new PinrollException('vendor/ not found locally — run composer install first.');
            }

            $deployRoot = HostDir::deployRoot(HostDir::fromTarget($target));
            $remoteVendor = ($deployRoot === '.' ? '' : $deployRoot . '/') . 'vendor';
            PushProgress::arrow('vendor/');
            $count = (new FtpUploader())->uploadDirectory($connection, $vendorLocal, $remoteVendor, 'vendor');
            $session->addStep('vendor', 'ok', 'vendor/ synced (' . $count . ' files)');
        }

        ftp_close($connection);
    }

    /**
     * @return array{0: string, 1: string} localPath, remoteBasename
     */
    private function resolveUploadPayload(string $archivePath, ReleaseManifest $manifest): array
    {
        $lower = strtolower($archivePath);
        if (str_ends_with($lower, '.pinx') || str_ends_with($lower, '.pin')) {
            return [$archivePath, basename($archivePath)];
        }

        if (!str_ends_with($lower, '.tar')) {
            return [$archivePath, basename($archivePath)];
        }

        $deployId = $manifest->deployId();
        $workDir = sys_get_temp_dir() . '/pinroll-ftp-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $deployId);
        $pinx = IncomingRelease::resolveInstallable($archivePath, $workDir);
        $remoteName = ($deployId !== '' ? $deployId : pathinfo(basename($pinx), PATHINFO_FILENAME)) . '.pinx';

        return [$pinx, $remoteName];
    }

    private function ftpMkdirRecursive($connection, string $path): void
    {
        $parts = array_filter(explode('/', str_replace('\\', '/', $path)));
        $current = '';

        foreach ($parts as $part) {
            $current = $current === '' ? $part : $current . '/' . $part;
            @ftp_mkdir($connection, $current);
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / (1024 * 1024), 1) . ' MB';
    }
}
