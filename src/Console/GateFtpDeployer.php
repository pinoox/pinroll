<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Support\HostDir;
use Pinoox\Pinroll\Support\PushProgress;
use Pinoox\Pinroll\Transport\FtpUploader;

/**
 * Upload a single pingate.php over FTP and remove a leftover remote gate/ folder.
 */
final class GateFtpDeployer
{
    /**
     * @param array<string, mixed> $resolvedTarget
     * @return array{remote_root: string, files: int}
     */
    public function upload(array $resolvedTarget, string $localEntry, ?string $localGateDir = null): array
    {
        unset($localGateDir);
        $host = (string) ($resolvedTarget['host'] ?? '');
        $user = (string) ($resolvedTarget['user'] ?? '');
        $password = (string) ($resolvedTarget['password'] ?? '');

        if ($host === '' || $user === '') {
            throw new PinrollException('FTP host/user required to upload PinGate.');
        }

        if (!is_file($localEntry)) {
            throw new PinrollException('Missing local PinGate entry: ' . $localEntry);
        }

        $uploader = new FtpUploader();
        $connection = $uploader->connect($host, $user, $password);

        try {
            $deployRoot = HostDir::deployRoot(HostDir::fromHost($resolvedTarget));
            $prefix = $deployRoot === '.' ? '' : rtrim($deployRoot, '/') . '/';

            $remoteEntry = $prefix . HostDir::GATE_ENTRY;
            PushProgress::arrow('FTP ' . $remoteEntry);
            $uploader->uploadFile($connection, $localEntry, $remoteEntry);
            $uploader->removeRemoteTree($connection, $prefix . HostDir::GATE_DIR);

            return [
                'remote_root' => $deployRoot === '.' ? HostDir::GATE_ENTRY : $deployRoot,
                'files' => 1,
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
