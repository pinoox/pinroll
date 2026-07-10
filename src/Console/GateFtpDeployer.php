<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Support\HostDir;
use Pinoox\Pinroll\Support\PushProgress;
use Pinoox\Pinroll\Transport\FtpUploader;

/**
 * Upload PinGate files (pingate.php + gate PHP stubs) over FTP — no zip, no vendor tree.
 */
final class GateFtpDeployer
{
    /** @var list<string> */
    private const GATE_FILES = [
        'bootstrap.php',
        'index.php',
        'pingate.php',
        '.htaccess',
    ];

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
            $deployRoot = HostDir::deployRoot(HostDir::fromHost($resolvedTarget));
            $prefix = $deployRoot === '.' ? '' : rtrim($deployRoot, '/') . '/';

            $remoteEntry = $prefix . HostDir::GATE_ENTRY;
            PushProgress::arrow('FTP ' . $remoteEntry);
            $uploader->uploadFile($connection, $localEntry, $remoteEntry);

            $remoteGate = $prefix . HostDir::GATE_DIR;
            PushProgress::arrow('FTP ' . $remoteGate . '/ (PinGate stubs only)');
            $count = 0;
            foreach (self::GATE_FILES as $name) {
                $local = rtrim($localGateDir, '/') . '/' . $name;
                if (!is_file($local)) {
                    continue;
                }
                $uploader->uploadFile($connection, $local, $remoteGate . '/' . $name);
                $count++;
            }

            // Optional fallback vendor (only when built with --with-vendor)
            $localVendor = rtrim($localGateDir, '/') . '/vendor';
            if (is_dir($localVendor) && is_file($localVendor . '/autoload.php')) {
                PushProgress::arrow('FTP ' . $remoteGate . '/vendor/ (optional fallback)');
                $count += $uploader->uploadDirectory($connection, $localVendor, $remoteGate . '/vendor', 'vendor');
            }

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
