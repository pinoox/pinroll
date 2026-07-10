<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Support\HostDir;
use Pinoox\Pinroll\Support\PushProgress;

/**
 * Upload PinGate files over SSH/SFTP.
 */
final class GateSshDeployer
{
    /** @var list<string> */
    private const GATE_FILES = [
        'bootstrap.php',
        'index.php',
        'pingate.php',
        '.htaccess',
    ];

    /**
     * @param array<string, mixed> $resolvedHost
     * @return array{remote_root: string, files: int}
     */
    public function upload(array $resolvedHost, string $localEntry, string $localGateDir): array
    {
        $host = (string) ($resolvedHost['host'] ?? '');
        $user = (string) ($resolvedHost['user'] ?? '');
        $password = (string) ($resolvedHost['password'] ?? '');
        $key = (string) ($resolvedHost['key'] ?? '');

        if ($host === '' || $user === '') {
            throw new PinrollException('SSH host/user required to upload PinGate.');
        }

        if (!class_exists(\phpseclib3\Net\SFTP::class)) {
            throw new PinrollException('SSH gate upload requires phpseclib/phpseclib.');
        }

        $sftp = new \phpseclib3\Net\SFTP($host);
        if ($key !== '' && is_file($key)) {
            $keyObj = \phpseclib3\Crypt\PublicKeyLoader::load((string) file_get_contents($key));
            if (!$sftp->login($user, $keyObj)) {
                throw new PinrollException('SFTP key login failed.');
            }
        } elseif (!$sftp->login($user, $password)) {
            throw new PinrollException('SFTP login failed.');
        }

        $deployRoot = HostDir::deployRoot(HostDir::fromHost($resolvedHost));
        $prefix = $deployRoot === '.' ? '' : rtrim($deployRoot, '/') . '/';
        $count = 0;

        $remoteEntry = $prefix . HostDir::GATE_ENTRY;
        PushProgress::arrow('SFTP ' . $remoteEntry);
        $this->ensureRemoteDir($sftp, dirname($remoteEntry));
        if (!$sftp->put($remoteEntry, $localEntry, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE)) {
            throw new PinrollException('SFTP upload failed: ' . $remoteEntry);
        }

        $remoteGate = $prefix . HostDir::GATE_DIR;
        PushProgress::arrow('SFTP ' . $remoteGate . '/');
        $this->ensureRemoteDir($sftp, $remoteGate);

        foreach (self::GATE_FILES as $name) {
            $local = rtrim($localGateDir, '/') . '/' . $name;
            if (!is_file($local)) {
                continue;
            }
            $sftp->put($remoteGate . '/' . $name, $local, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE);
            $count++;
        }

        return [
            'remote_root' => $deployRoot === '.' ? HostDir::GATE_ENTRY : $deployRoot,
            'files' => 1 + $count,
        ];
    }

    private function ensureRemoteDir(\phpseclib3\Net\SFTP $sftp, string $dir): void
    {
        if ($dir === '.' || $dir === '') {
            return;
        }

        if (!$sftp->is_dir($dir)) {
            $sftp->mkdir($dir, -1, true);
        }
    }

    /**
     * @param array<string, mixed> $resolvedHost
     */
    public static function canUpload(array $resolvedHost): bool
    {
        return (string) ($resolvedHost['transport'] ?? '') === 'ssh'
            && (string) ($resolvedHost['host'] ?? '') !== ''
            && (string) ($resolvedHost['user'] ?? '') !== '';
    }
}
