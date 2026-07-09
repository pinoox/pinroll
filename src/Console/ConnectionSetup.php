<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Support\HostDir;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ConnectionSetup
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function collect(SymfonyStyle $io, string $targetName = 'production'): array
    {
        $io->section('Pinroll setup');

        $dir = HostDir::normalize((string) $io->ask(
            'FTP deploy path (empty = login root; e.g. public_html or public_html/shop)',
            '',
        ));
        $via = (string) $io->choice('Default transport', [
            'ftp' => 'FTP',
            'ssh' => 'SSH',
            'pinion' => 'Pinion (PinGate HTTP upload)',
        ], 'ftp');

        $target = SampleConfig::productionTarget($targetName);
        $target['dir'] = $dir;
        $target['via'] = $via;

        if ($via === 'ftp' || $io->confirm('Configure FTP?', $via === 'ftp')) {
            $target['ftp'] = self::collectFtpBlock($io, $targetName);
        }

        if ($via === 'ssh' || $io->confirm('Also configure SSH?', false)) {
            $target['ssh'] = self::collectSshBlock($io, $targetName);
        }

        // PinGate credentials are set in GateSetupWizard as top-level gate
        if ($via === 'pinion' && !isset($target['ftp'])) {
            $target['ftp'] = SampleConfig::productionTarget($targetName)['ftp'];
        }

        $apps = AppPicker::collect($io);
        if ($apps !== null) {
            $target['apps'] = $apps;
        }

        return [$targetName => $target];
    }

    /**
     * @return array<string, mixed>
     */
    private static function collectFtpBlock(SymfonyStyle $io, string $targetName): array
    {
        $host = trim((string) $io->ask('FTP host (e.g. ftp.pinoox.com)', ''));
        $user = trim((string) $io->ask('FTP user', ''));

        return [
            'host' => ['_env' => ConfigWriter::envKeyFor($targetName, 'host', 'ftp'), 'default' => $host],
            'user' => ['_env' => ConfigWriter::envKeyFor($targetName, 'user', 'ftp'), 'default' => $user],
            'password' => [
                '_env' => ConfigWriter::envKeyFor($targetName, 'password', 'ftp'),
                'default' => trim((string) $io->ask('FTP password', '')),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function collectSshBlock(SymfonyStyle $io, string $targetName): array
    {
        $host = trim((string) $io->ask('SSH host (e.g. pinoox.com)', ''));
        $user = trim((string) $io->ask('SSH user', ''));
        $block = [
            'host' => ['_env' => ConfigWriter::envKeyFor($targetName, 'host', 'ssh'), 'default' => $host],
            'user' => ['_env' => ConfigWriter::envKeyFor($targetName, 'user', 'ssh'), 'default' => $user],
            'password' => ['_env' => ConfigWriter::envKeyFor($targetName, 'password', 'ssh'), 'default' => ''],
            'key' => ['_env' => ConfigWriter::envKeyFor($targetName, 'key', 'ssh'), 'default' => ''],
        ];

        $auth = (string) $io->choice('SSH auth', ['password' => 'Password', 'key' => 'Private key'], 'password');
        if ($auth === 'key') {
            $block['key']['default'] = trim((string) $io->ask('Path to private key', ''));
        } else {
            $block['password']['default'] = trim((string) $io->ask('SSH password', ''));
        }

        return $block;
    }
}
