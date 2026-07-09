<?php

namespace Pinoox\Pinroll\Transport;

use Pinoox\Pinroll\Exception\PinrollException;

final class TransportConnector
{
    /**
     * @param array<string, mixed> $target
     */
    public static function test(array $target): void
    {
        $transport = (string) ($target['transport'] ?? 'ftp');

        match ($transport) {
            'ftp' => self::testFtp($target),
            'ssh' => self::testSsh($target),
            'local' => null,
            'pinion' => null,
            default => throw new PinrollException('Unknown transport: ' . $transport),
        };
    }

    /**
     * @param array<string, mixed> $target
     */
    private static function testFtp(array $target): void
    {
        $host = (string) ($target['host'] ?? '');
        $user = (string) ($target['user'] ?? '');
        $password = (string) ($target['password'] ?? '');

        if ($host === '' || $user === '') {
            throw new PinrollException('FTP host and user are required.');
        }

        if (!function_exists('ftp_connect')) {
            throw new PinrollException('PHP FTP extension is not available.');
        }

        $connection = @ftp_connect($host, 21, 15);
        if ($connection === false) {
            throw new PinrollException('Cannot connect to FTP host.');
        }

        if (!@ftp_login($connection, $user, $password)) {
            ftp_close($connection);
            throw new PinrollException('FTP login failed — check host, user, and password.');
        }

        ftp_close($connection);
    }

    /**
     * @param array<string, mixed> $target
     */
    private static function testSsh(array $target): void
    {
        $host = (string) ($target['host'] ?? '');
        $user = (string) ($target['user'] ?? '');
        $password = (string) ($target['password'] ?? '');
        $key = (string) ($target['key'] ?? '');

        if ($host === '' || $user === '') {
            throw new PinrollException('SSH host and user are required.');
        }

        if (class_exists(\phpseclib3\Net\SSH2::class)) {
            $ssh = new \phpseclib3\Net\SSH2($host, 22, 10);
            $loggedIn = $key !== '' && is_file($key)
                ? $ssh->login($user, \phpseclib3\Crypt\PublicKeyLoader::load((string) file_get_contents($key)))
                : $ssh->login($user, $password);

            if (!$loggedIn) {
                throw new PinrollException('SSH login failed — check credentials.');
            }

            return;
        }

        $cmd = 'ssh -o BatchMode=yes -o ConnectTimeout=10 -o StrictHostKeyChecking=accept-new '
            . ($key !== '' ? '-i ' . escapeshellarg($key) . ' ' : '')
            . escapeshellarg($user . '@' . $host)
            . ' echo ok 2>&1';
        exec($cmd, $output, $code);

        if ($code !== 0) {
            throw new PinrollException('SSH connection failed — check credentials.');
        }
    }
}
