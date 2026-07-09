<?php

namespace Pinoox\Pinroll\Transport;

use Pinoox\Pinroll\Contract\TransportInterface;
use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Release\ReleaseManifest;
use Pinoox\Pinroll\Rollout\RolloutSession;
use Pinoox\Pinroll\Support\Config;
use Pinoox\Pinroll\Support\HostDir;

final class SshTransport implements TransportInterface
{
    public function __construct(private readonly Config $config)
    {
    }

    public function name(): string
    {
        return 'ssh';
    }

    public function send(string $archivePath, ReleaseManifest $manifest, array $target, RolloutSession $session): void
    {
        $host = (string) ($target['host'] ?? '');
        $user = (string) ($target['user'] ?? '');
        $key = (string) ($target['key'] ?? '');
        $password = (string) ($target['password'] ?? '');
        $remotePath = HostDir::incomingFromTarget($target, basename($archivePath));

        if ($host === '' || $user === '') {
            throw new PinrollException('SSH target requires host and user.');
        }

        if (class_exists(\phpseclib3\Net\SFTP::class)) {
            $this->sendViaPhpseclib($archivePath, $host, $user, $key, $remotePath, $password, $session);

            return;
        }

        $destination = $user . '@' . $host . ':' . $remotePath;
        $cmd = 'scp -q ' . ($key !== '' ? '-i ' . escapeshellarg($key) . ' ' : '') . escapeshellarg($archivePath) . ' ' . escapeshellarg($destination);
        exec($cmd . ' 2>&1', $output, $code);

        if ($code !== 0) {
            throw new PinrollException('SCP failed: ' . implode("\n", $output));
        }

        $session->addStep('transport', 'ok', 'Archive uploaded via SCP to ' . $remotePath);
    }

    private function sendViaPhpseclib(string $archivePath, string $host, string $user, string $key, string $remotePath, string $password, RolloutSession $session): void
    {
        $sftp = new \phpseclib3\Net\SFTP($host);
        if ($key !== '') {
            $keyObj = \phpseclib3\Crypt\PublicKeyLoader::load(file_get_contents($key));
            if (!$sftp->login($user, $keyObj)) {
                throw new PinrollException('SFTP key login failed.');
            }
        } elseif (!$sftp->login($user, $password)) {
            throw new PinrollException('SFTP login failed.');
        }

        $remoteDir = dirname($remotePath);
        if ($remoteDir !== '.' && !$sftp->is_dir($remoteDir)) {
            $sftp->mkdir($remoteDir, -1, true);
        }

        if (!$sftp->put($remotePath, $archivePath, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE)) {
            throw new PinrollException('SFTP upload failed.');
        }

        $session->addStep('transport', 'ok', 'Archive uploaded via SFTP to ' . $remotePath);
    }
}
