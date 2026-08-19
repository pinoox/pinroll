<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Support\HostDir;
use Pinoox\Pinroll\Support\PushProgress;

/**
 * Upload a single pingate.php over SSH/SFTP and remove a leftover remote gate/ folder.
 */
final class GateSshDeployer
{
    /**
     * @param array<string, mixed> $resolvedHost
     * @return array{remote_root: string, files: int}
     */
    public function upload(array $resolvedHost, string $localEntry, ?string $localGateDir = null): array
    {
        unset($localGateDir);
        $host = (string) ($resolvedHost['host'] ?? '');
        $user = (string) ($resolvedHost['user'] ?? '');
        $password = (string) ($resolvedHost['password'] ?? '');
        $key = (string) ($resolvedHost['key'] ?? '');

        if ($host === '' || $user === '') {
            throw new PinrollException('SSH host/user required to upload PinGate.');
        }

        if (!is_file($localEntry)) {
            throw new PinrollException('Missing local PinGate entry: ' . $localEntry);
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

        $remoteEntry = $prefix . HostDir::GATE_ENTRY;
        PushProgress::arrow('SFTP ' . $remoteEntry);
        $this->ensureRemoteDir($sftp, dirname($remoteEntry));
        if (!$sftp->put($remoteEntry, $localEntry, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE)) {
            throw new PinrollException('SFTP upload failed: ' . $remoteEntry);
        }

        $this->removeRemoteTree($sftp, $prefix . HostDir::GATE_DIR);

        return [
            'remote_root' => $deployRoot === '.' ? HostDir::GATE_ENTRY : $deployRoot,
            'files' => 1,
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

    private function removeRemoteTree(\phpseclib3\Net\SFTP $sftp, string $dir, bool $mustBeGate = true): void
    {
        $dir = trim(str_replace('\\', '/', $dir), '/');
        if ($dir === '' || $dir === '.') {
            return;
        }
        if ($mustBeGate && basename($dir) !== HostDir::GATE_DIR) {
            return;
        }

        if (!$sftp->is_dir($dir)) {
            return;
        }

        if ($mustBeGate) {
            PushProgress::arrow('SFTP remove leftover ' . $dir . '/');
        }

        $list = $sftp->nlist($dir);
        if (!is_array($list)) {
            $sftp->rmdir($dir);

            return;
        }

        foreach ($list as $item) {
            $name = basename(str_replace('\\', '/', (string) $item));
            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            if ($sftp->is_dir($path)) {
                $this->removeRemoteTree($sftp, $path, false);
            } else {
                $sftp->delete($path);
            }
        }

        $sftp->rmdir($dir);
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
