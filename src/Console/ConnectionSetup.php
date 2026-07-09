<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Support\HostDir;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ConnectionSetup
{
    /**
     * Interactive transport + credentials. Always writes env-backed ftp/ssh blocks when selected.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function collect(SymfonyStyle $io, string $targetName = 'production', ?string $projectRoot = null): array
    {
        $io->section('Target & transport');

        $via = (string) $io->choice('Transport', [
            'ftp' => 'FTP (shared hosting / cPanel)',
            'ssh' => 'SSH / SFTP (VPS)',
            'pinion' => 'Pinion (HTTP upload via PinGate)',
        ], 'ftp');

        $dirDefault = $via === 'ftp' ? 'public_html' : '';
        $dir = HostDir::normalize((string) $io->ask(
            'Deploy path relative to FTP/SSH login (empty = login root; e.g. public_html or public_html/shop)',
            $dirDefault,
        ));

        $target = [
            'dir' => $dir,
            'via' => $via,
        ];

        $envValues = [];

        if ($via === 'ftp') {
            [$ftpBlock, $ftpEnv] = self::collectFtp($io, $targetName);
            $target['ftp'] = $ftpBlock;
            $envValues = array_merge($envValues, $ftpEnv);
        } elseif ($via === 'ssh') {
            [$sshBlock, $sshEnv] = self::collectSsh($io, $targetName);
            $target['ssh'] = $sshBlock;
            $envValues = array_merge($envValues, $sshEnv);
        } else {
            // pinion: still offer optional FTP for later push without HTTP upload
            if ($io->confirm('Also add an FTP block for later use?', false)) {
                [$ftpBlock, $ftpEnv] = self::collectFtp($io, $targetName);
                $target['ftp'] = $ftpBlock;
                $envValues = array_merge($envValues, $ftpEnv);
            }
        }

        if ($via === 'ftp' && $io->confirm('Also configure SSH?', false)) {
            [$sshBlock, $sshEnv] = self::collectSsh($io, $targetName);
            $target['ssh'] = $sshBlock;
            $envValues = array_merge($envValues, $sshEnv);
        }

        $apps = AppPicker::collect($io);
        if ($apps !== null) {
            $target['apps'] = $apps;
        }

        $root = $projectRoot ?? (defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd());
        if ($envValues !== []) {
            self::writeEnvKeys($io, (string) $root, $envValues);
        }

        return [$targetName => $target];
    }

    /**
     * Ensure env-backed FTP stubs exist when via=ftp but credentials were skipped earlier.
     *
     * @param array<string, mixed> $target
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public static function ftpStub(string $targetName): array
    {
        $keys = [
            'host' => ConfigWriter::envKeyFor($targetName, 'host', 'ftp'),
            'user' => ConfigWriter::envKeyFor($targetName, 'user', 'ftp'),
            'password' => ConfigWriter::envKeyFor($targetName, 'password', 'ftp'),
        ];

        $block = [
            'host' => ['_env' => $keys['host'], 'default' => ''],
            'user' => ['_env' => $keys['user'], 'default' => ''],
            'password' => ['_env' => $keys['password'], 'default' => ''],
        ];

        $env = [
            $keys['host'] => '',
            $keys['user'] => '',
            $keys['password'] => '',
        ];

        return [$block, $env];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private static function collectFtp(SymfonyStyle $io, string $targetName): array
    {
        [$block, $env] = self::ftpStub($targetName);

        $io->section('FTP credentials');
        $io->text([
            'Values are stored in .env (PINROLL_*_HOST / USER / PASSWORD).',
            'You can skip and fill .env later — keys will still be created.',
        ]);

        if (!$io->confirm('Enter FTP credentials now?', true)) {
            $io->note([
                'Skipped — add these to .env when ready:',
                ConfigWriter::envKeyFor($targetName, 'host', 'ftp') . '=',
                ConfigWriter::envKeyFor($targetName, 'user', 'ftp') . '=',
                ConfigWriter::envKeyFor($targetName, 'password', 'ftp') . '=',
            ]);

            return [$block, $env];
        }

        $host = trim((string) $io->ask('FTP host (e.g. ftp.pinoox.com)', ''));
        $user = trim((string) $io->ask('FTP user', ''));
        $password = trim((string) $io->ask('FTP password', ''));

        $hostKey = ConfigWriter::envKeyFor($targetName, 'host', 'ftp');
        $userKey = ConfigWriter::envKeyFor($targetName, 'user', 'ftp');
        $passKey = ConfigWriter::envKeyFor($targetName, 'password', 'ftp');

        $block = [
            'host' => ['_env' => $hostKey, 'default' => $host],
            'user' => ['_env' => $userKey, 'default' => $user],
            'password' => ['_env' => $passKey, 'default' => $password],
        ];

        $env = [
            $hostKey => $host,
            $userKey => $user,
            $passKey => $password,
        ];

        return [$block, $env];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private static function collectSsh(SymfonyStyle $io, string $targetName): array
    {
        $io->section('SSH credentials');
        $io->text('Values go to .env (PINROLL_*_SSH_*). You can skip and fill later.');

        $hostKey = ConfigWriter::envKeyFor($targetName, 'host', 'ssh');
        $userKey = ConfigWriter::envKeyFor($targetName, 'user', 'ssh');
        $passKey = ConfigWriter::envKeyFor($targetName, 'password', 'ssh');
        $keyKey = ConfigWriter::envKeyFor($targetName, 'key', 'ssh');

        $block = [
            'host' => ['_env' => $hostKey, 'default' => ''],
            'user' => ['_env' => $userKey, 'default' => ''],
            'password' => ['_env' => $passKey, 'default' => ''],
            'key' => ['_env' => $keyKey, 'default' => ''],
        ];
        $env = [
            $hostKey => '',
            $userKey => '',
            $passKey => '',
            $keyKey => '',
        ];

        if (!$io->confirm('Enter SSH credentials now?', true)) {
            $io->note('Skipped — fill ' . $hostKey . ' / ' . $userKey . ' in .env later.');

            return [$block, $env];
        }

        $host = trim((string) $io->ask('SSH host (e.g. pinoox.com)', ''));
        $user = trim((string) $io->ask('SSH user', ''));
        $auth = (string) $io->choice('SSH auth', ['password' => 'Password', 'key' => 'Private key'], 'password');

        $password = '';
        $key = '';
        if ($auth === 'key') {
            $key = trim((string) $io->ask('Path to private key', ''));
        } else {
            $password = trim((string) $io->ask('SSH password', ''));
        }

        $block = [
            'host' => ['_env' => $hostKey, 'default' => $host],
            'user' => ['_env' => $userKey, 'default' => $user],
            'password' => ['_env' => $passKey, 'default' => $password],
            'key' => ['_env' => $keyKey, 'default' => $key],
        ];
        $env = [
            $hostKey => $host,
            $userKey => $user,
            $passKey => $password,
            $keyKey => $key,
        ];

        return [$block, $env];
    }

    /**
     * @param array<string, string> $values
     */
    private static function writeEnvKeys(SymfonyStyle $io, string $projectRoot, array $values): void
    {
        $envPath = rtrim($projectRoot, '/') . '/.env';
        // Only create missing keys as empty; never wipe existing secrets with empty defaults
        $toWrite = [];
        foreach ($values as $key => $value) {
            $existing = self::readEnvValue($envPath, $key);
            if ($existing !== null && $existing !== '' && $value === '') {
                continue;
            }
            if ($existing !== null && $value === '' && $existing === '') {
                continue;
            }
            if ($existing === null || $value !== '') {
                $toWrite[$key] = $value;
            }
        }

        if ($toWrite === []) {
            $io->writeln('  <fg=gray>.env already has transport keys</>');

            return;
        }

        EnvFileWriter::merge($envPath, $toWrite, '# Pinroll');
        $io->writeln('  <fg=green>Updated</> .env transport keys:');
        foreach (array_keys($toWrite) as $key) {
            $io->writeln('    <comment>' . $key . '</comment>');
        }
    }

    private static function readEnvValue(string $path, string $key): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return null;
        }

        $pattern = '/^' . preg_quote($key, '/') . '\s*=\s*(.*)$/';
        foreach ($lines as $line) {
            if (!preg_match($pattern, (string) $line, $m)) {
                continue;
            }

            $raw = trim($m[1]);
            if (
                (str_starts_with($raw, '"') && str_ends_with($raw, '"'))
                || (str_starts_with($raw, "'") && str_ends_with($raw, "'"))
            ) {
                $raw = substr($raw, 1, -1);
            }

            return $raw;
        }

        return null;
    }
}
