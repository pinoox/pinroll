<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Support\HostDir;
use Pinoox\Pinroll\Support\PushProgress;
use Pinoox\Pinroll\Transport\FtpUploader;

/**
 * Upload PinGate files (pingate.php + gate/) over FTP — no zip.
 */
final class GateFtpDeployer
{
    /**
     * @param array<string, mixed> $resolvedTarget
     * @return array{remote_root: string, files: int}
     */
    public function upload(array $resolvedTarget, string $localEntry, string $localGateDir): array
    {
        $host = (string) ($resolvedTarget['host'] ?? '');
        $user = (string) ($resolvedTarget['user'] ?? '');
        $password = (string) ($resolvedTarget['password'] ?? '');

        if ($host === '' || $user === '') {
            throw new PinrollException('FTP host/user required to upload PinGate.');
        }

        if (!is_file($localEntry)) {
            throw new PinrollException('Missing local PinGate entry: ' . $localEntry);
        }

        if (!is_dir($localGateDir)) {
            throw new PinrollException('Missing local PinGate dir: ' . $localGateDir);
        }

        $uploader = new FtpUploader();
        $connection = $uploader->connect($host, $user, $password);

        try {
            $deployRoot = HostDir::deployRoot(HostDir::fromTarget($resolvedTarget));
            $prefix = $deployRoot === '.' ? '' : rtrim($deployRoot, '/') . '/';

            $remoteEntry = $prefix . HostDir::GATE_ENTRY;
            PushProgress::arrow('FTP ' . $remoteEntry);
            $uploader->uploadFile($connection, $localEntry, $remoteEntry);

            $remoteGate = $prefix . HostDir::GATE_DIR;
            PushProgress::arrow('FTP ' . $remoteGate . '/');
            $count = $uploader->uploadDirectory($connection, $localGateDir, $remoteGate, 'gate');

            return [
                'remote_root' => $deployRoot === '.' ? HostDir::GATE_ENTRY : $deployRoot,
                'files' => 1 + $count,
            ];
        } finally {
            ftp_close($connection);
        }
    }

    /**
     * @param array<string, mixed> $resolvedTarget
     */
    public static function canUpload(array $resolvedTarget): bool
    {
        return (string) ($resolvedTarget['transport'] ?? '') === 'ftp'
            && (string) ($resolvedTarget['host'] ?? '') !== ''
            && (string) ($resolvedTarget['user'] ?? '') !== '';
    }
}
