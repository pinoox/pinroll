<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Host\HostGate;
use Pinoox\Pinroll\PinGate\GateTokenRegistry;
use Pinoox\Pinroll\Support\HostDir;
use Pinoox\Pinroll\Support\PushProgress;
use Pinoox\Pinroll\Support\TokenGenerator;
use Pinoox\Pinroll\Transport\FtpUploader;

/**
 * Write storage/pinroll/tokens/{label}.php locally and upload it when FTP/SSH is available.
 */
final class GateTokenSyncer
{
    /**
     * @param array<string, mixed> $resolved
     * @param array<string, mixed> $raw
     * @return array{label: string, local: string, remote: string, uploaded: bool}
     */
    public static function sync(
        string $root,
        string $hostName,
        array $resolved,
        array $raw,
        ?string $plainToken = null,
        ?string $label = null,
    ): array {
        $gate = HostGate::credentials($raw);
        $token = $plainToken !== null && $plainToken !== '' ? $plainToken : $gate['token'];
        if ($token === '') {
            $token = TokenGenerator::token();
        }

        $label = $label !== null && $label !== ''
            ? GateTokenRegistry::normalizeLabel($label)
            : GateTokenRegistry::labelFromHost($raw);

        $local = GateTokenRegistry::writeTokenFile($root, $label, $token);
        $remoteRel = GateTokenRegistry::hostUploadPath($label);
        $site = $gate['site'] !== '' ? $gate['site'] : ($gate['url'] !== '' ? GateUrl::siteFrom($gate['url']) : 'https://example.com');
        OverlayWriter::persistGate($root, $hostName, $site, $token, null, $label);

        $uploaded = false;
        $ftp = self::ftpTarget($resolved, $raw);
        if ($ftp !== null) {
            self::uploadFtp($ftp, $local, $remoteRel);
            $uploaded = true;
        } elseif (self::sshTarget($resolved, $raw) !== null) {
            self::uploadSsh(self::sshTarget($resolved, $raw), $local, $remoteRel);
            $uploaded = true;
        }

        if ($uploaded) {
            PushProgress::detail('Host token: ' . $remoteRel);
        }

        return [
            'label' => $label,
            'local' => $local,
            'remote' => $remoteRel,
            'uploaded' => $uploaded,
        ];
    }

    /**
     * @param array<string, mixed> $resolved
     * @param array<string, mixed> $raw
     */
    public static function canUpload(array $resolved, array $raw): bool
    {
        return self::ftpTarget($resolved, $raw) !== null || self::sshTarget($resolved, $raw) !== null;
    }

    /**
     * FTP credentials from the current transport or the host ftp block (even when via=pinion).
     *
     * @param array<string, mixed> $resolved
     * @param array<string, mixed> $raw
     * @return array<string, mixed>|null
     */
    public static function ftpTarget(array $resolved, array $raw): ?array
    {
        if (GateFtpDeployer::canUpload($resolved)) {
            return $resolved;
        }

        $ftp = is_array($raw['ftp'] ?? null) ? $raw['ftp'] : [];
        $host = trim((string) ($ftp['host'] ?? $raw['hostname'] ?? $raw['host'] ?? ''));
        $user = trim((string) ($ftp['user'] ?? $raw['user'] ?? ''));
        $password = (string) ($ftp['password'] ?? $raw['password'] ?? '');

        if ($host === '' || $user === '') {
            return null;
        }

        return array_merge($resolved, [
            'transport' => 'ftp',
            'host' => $host,
            'user' => $user,
            'password' => $password,
        ]);
    }

    /**
     * @param array<string, mixed> $resolved
     * @param array<string, mixed> $raw
     * @return array<string, mixed>|null
     */
    private static function sshTarget(array $resolved, array $raw): ?array
    {
        if (GateSshDeployer::canUpload($resolved)) {
            return $resolved;
        }

        $ssh = is_array($raw['ssh'] ?? null) ? $raw['ssh'] : [];
        $host = trim((string) ($ssh['host'] ?? ''));
        $user = trim((string) ($ssh['user'] ?? ''));
        if ($host === '' || $user === '') {
            return null;
        }

        return array_merge($resolved, [
            'transport' => 'ssh',
            'host' => $host,
            'user' => $user,
            'password' => (string) ($ssh['password'] ?? ''),
            'key' => (string) ($ssh['key'] ?? ''),
        ]);
    }

    /**
     * @param array<string, mixed> $target
     */
    private static function uploadFtp(array $target, string $localFile, string $remoteRel): void
    {
        $host = (string) ($target['host'] ?? '');
        $user = (string) ($target['user'] ?? '');
        $password = (string) ($target['password'] ?? '');
        $deployRoot = HostDir::deployRoot(HostDir::fromHost($target));
        $prefix = $deployRoot === '.' ? '' : rtrim($deployRoot, '/') . '/';
        $remoteFile = $prefix . $remoteRel;

        PushProgress::arrow('FTP ' . $remoteFile);
        $uploader = new FtpUploader();
        try {
            $uploader->uploadFileCurl($host, $user, $password, $localFile, $remoteFile);
        } catch (\Throwable $curlError) {
            PushProgress::detail('cURL FTP failed — trying PHP FTP: ' . $curlError->getMessage());
            $connection = $uploader->connect($host, $user, $password);
            try {
                $uploader->uploadFile($connection, $localFile, $remoteFile);
            } finally {
                if (is_resource($connection) || (is_object($connection) && $connection instanceof \FTP\Connection)) {
                    @ftp_close($connection);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $target
     */
    private static function uploadSsh(array $target, string $localFile, string $remoteRel): void
    {
        if (!class_exists(\phpseclib3\Net\SFTP::class)) {
            throw new PinrollException('SSH token upload requires phpseclib/phpseclib.');
        }

        $host = (string) ($target['host'] ?? '');
        $user = (string) ($target['user'] ?? '');
        $password = (string) ($target['password'] ?? '');
        $key = (string) ($target['key'] ?? '');

        $sftp = new \phpseclib3\Net\SFTP($host);
        if ($key !== '' && is_file($key)) {
            $keyObj = \phpseclib3\Crypt\PublicKeyLoader::load((string) file_get_contents($key));
            if (!$sftp->login($user, $keyObj)) {
                throw new PinrollException('SFTP key login failed.');
            }
        } elseif (!$sftp->login($user, $password)) {
            throw new PinrollException('SFTP login failed.');
        }

        $deployRoot = HostDir::deployRoot(HostDir::fromHost($target));
        $prefix = $deployRoot === '.' ? '' : rtrim($deployRoot, '/') . '/';
        $remoteFile = $prefix . $remoteRel;
        PushProgress::arrow('SFTP ' . $remoteFile);
        $dir = dirname($remoteFile);
        if ($dir !== '.' && $dir !== '' && !$sftp->is_dir($dir)) {
            $sftp->mkdir($dir, -1, true);
        }
        if (!$sftp->put($remoteFile, $localFile, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE)) {
            throw new PinrollException('SFTP upload failed: ' . $remoteFile);
        }
    }
}
