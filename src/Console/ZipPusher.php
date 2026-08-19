<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Host\HostGate;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\HostDir;
use Pinoox\Pinroll\Support\PushProgress;
use Pinoox\Pinroll\Transport\FtpUploader;

/**
 * Upload a zip next to pingate.php (FTP or SFTP) then run a PinGate extract route.
 */
final class ZipPusher
{
    /**
     * @param callable(string, string): array<string, mixed> $extract
     * @return array{remote_zip: string, extract: array<string, mixed>}
     */
    public function push(string $hostName, string $localZip, string $remoteName, callable $extract): array
    {
        if (!is_file($localZip)) {
            throw new PinrollException('Zip not found: ' . $localZip);
        }

        $remoteName = basename($remoteName);
        if ($remoteName === '' || !preg_match('/^[a-zA-Z0-9._-]+\.zip$/', $remoteName)) {
            throw new PinrollException('Invalid remote zip name.');
        }

        $raw = Pinroll::hosts()->raw($hostName);
        $resolved = Pinroll::hosts()->resolve($hostName);
        $transport = (string) ($resolved['transport'] ?? 'ftp');

        $gate = HostGate::credentials($raw);
        $gateUrl = $gate['url'] !== '' ? $gate['url'] : (string) ($resolved['gate_url'] ?? '');
        $token = $gate['token'] !== '' ? $gate['token'] : (string) ($resolved['token'] ?? '');

        if ($gateUrl === '' || $token === '') {
            throw new PinrollException(
                'PinGate URL/token missing. Run php pinoox pinroll:connect / pinroll:gate first.',
            );
        }

        $prefix = HostDir::deployRoot(HostDir::fromTarget($resolved));
        $prefix = $prefix === '.' ? '' : rtrim($prefix, '/') . '/';
        $remoteZip = $prefix . $remoteName;

        match ($transport) {
            'ftp' => $this->uploadFtp($resolved, $localZip, $remoteZip),
            'ssh' => $this->uploadSsh($resolved, $localZip, $remoteZip),
            default => throw new PinrollException(
                'Zip upload for provision/vendor supports FTP or SSH. Host transport: ' . $transport,
            ),
        };

        PushProgress::arrow('PinGate extract ' . $remoteName);
        $result = $extract($gateUrl, $token);

        return [
            'remote_zip' => $remoteZip,
            'extract' => $result,
        ];
    }

    /**
     * @param array<string, mixed> $resolved
     */
    private function uploadFtp(array $resolved, string $localZip, string $remoteZip): void
    {
        $uploader = new FtpUploader();
        $connection = $uploader->connect(
            (string) ($resolved['host'] ?? ''),
            (string) ($resolved['user'] ?? ''),
            (string) ($resolved['password'] ?? ''),
        );

        try {
            PushProgress::arrow('FTP upload ' . basename($localZip) . ' → ' . $remoteZip);
            $uploader->uploadFile($connection, $localZip, $remoteZip);
            PushProgress::arrow('FTP upload done');
        } finally {
            if (is_resource($connection)) {
                ftp_close($connection);
            }
        }
    }

    /**
     * @param array<string, mixed> $resolved
     */
    private function uploadSsh(array $resolved, string $localZip, string $remoteZip): void
    {
        if (!class_exists(\phpseclib3\Net\SFTP::class)) {
            throw new PinrollException('SSH zip upload requires phpseclib/phpseclib.');
        }

        $host = (string) ($resolved['host'] ?? '');
        $user = (string) ($resolved['user'] ?? '');
        $password = (string) ($resolved['password'] ?? '');
        $key = (string) ($resolved['key'] ?? '');

        $sftp = new \phpseclib3\Net\SFTP($host);
        if ($key !== '' && is_file($key)) {
            $keyObj = \phpseclib3\Crypt\PublicKeyLoader::load((string) file_get_contents($key));
            if (!$sftp->login($user, $keyObj)) {
                throw new PinrollException('SFTP key login failed.');
            }
        } elseif (!$sftp->login($user, $password)) {
            throw new PinrollException('SFTP login failed.');
        }

        $dir = dirname($remoteZip);
        if ($dir !== '.' && $dir !== '' && !$sftp->is_dir($dir)) {
            $sftp->mkdir($dir, -1, true);
        }

        PushProgress::arrow('SFTP upload ' . basename($localZip) . ' → ' . $remoteZip);
        if (!$sftp->put($remoteZip, $localZip, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE)) {
            throw new PinrollException('SFTP upload failed: ' . $remoteZip);
        }
        PushProgress::arrow('SFTP upload done');
    }
}
