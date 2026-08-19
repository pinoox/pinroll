<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Host\HostGate;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Release\BuiltinBundle;
use Pinoox\Pinroll\Release\ReleaseBundle;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\PinrollAutoloader;
use Pinoox\Pinroll\Support\PushProgress;
use Pinoox\Pinroll\Target\PinGateClient;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * First-time blank-host install: PinGate → platform.zip → SetupService.
 */
final class ProvisionRunner
{
    public function __construct(
        private readonly string $projectRoot,
    ) {
        PinrollAutoloader::register($this->projectRoot);
        Pinroll::boot(new NativePathResolver($this->projectRoot));
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function run(SymfonyStyle $io, string $hostName, array $options = []): array
    {
        $via = (string) ($options['via'] ?? '');
        $raw = Pinroll::hosts()->raw($hostName);
        $resolved = Pinroll::hosts()->resolve($hostName, $via !== '' ? $via : null);
        $interactive = !empty($options['interactive']);
        $setupOnly = !empty($options['setup_only']);
        $force = !empty($options['force']);
        $reupload = !empty($options['reupload']);

        $io->writeln('');
        $io->writeln('  Host PHP needs ZipArchive and enough time/memory for extract + migrations (up to 10 minutes).');
        $io->writeln('  The database must be reachable <comment>from the host</comment> (shared MySQL is often localhost-only).');
        $io->newLine();

        $cliProvision = [
            'db' => array_filter([
                'host' => $options['db_host'] ?? null,
                'database' => $options['db_database'] ?? null,
                'username' => $options['db_username'] ?? null,
                'password' => $options['db_password'] ?? null,
                'connection' => $options['db_connection'] ?? null,
                'port' => $options['db_port'] ?? null,
                'prefix' => $options['db_prefix'] ?? null,
                'timezone' => $options['db_timezone'] ?? null,
            ], static fn (mixed $v): bool => $v !== null && $v !== ''),
            'user' => array_filter([
                'fname' => $options['admin_fname'] ?? null,
                'lname' => $options['admin_lname'] ?? null,
                'email' => $options['admin_email'] ?? null,
                'username' => $options['admin_username'] ?? null,
                'password' => $options['admin_password'] ?? null,
            ], static fn (mixed $v): bool => $v !== null && $v !== ''),
            'lang' => $options['lang'] ?? null,
        ];

        $credentials = $this->collectCredentials(
            $io,
            ProvisionSettings::resolve($raw, $cliProvision),
            $interactive,
        );

        $result = [
            'host' => $hostName,
            'setup_only' => $setupOnly,
            'bootstrap' => null,
            'check_db' => null,
            'setup' => null,
        ];

        if (!$setupOnly) {
            PushProgress::arrow('Upload pingate.php if missing');
            (new DeployRunner($this->projectRoot))->initGate($hostName, false, null, null, false, true, false);

            $archive = $this->buildPlatformZip();
            PushProgress::arrow('Upload platform.zip');
            $pushed = (new ZipPusher())->push(
                $hostName,
                $archive,
                'platform.zip',
                static fn (string $url, string $token): array => PinGateClient::bootstrap($url, $token, [
                    'force' => $force || $reupload,
                ]),
            );
            $result['bootstrap'] = $pushed['extract'];
        }

        $gate = HostGate::credentials($raw);
        $gateUrl = $gate['url'] !== '' ? $gate['url'] : (string) ($resolved['gate_url'] ?? '');
        $token = $gate['token'] !== '' ? $gate['token'] : (string) ($resolved['token'] ?? '');
        if ($gateUrl === '' || $token === '') {
            throw new PinrollException('PinGate URL/token missing after gate upload. Run pinroll:connect first.');
        }

        PushProgress::arrow('Test database from the host');
        $check = PinGateClient::checkDb($gateUrl, $token, $credentials['db']);
        $result['check_db'] = $check;
        if (empty($check['ok'])) {
            throw new PinrollException((string) ($check['message'] ?? 'Database connection failed from the host.'));
        }

        PushProgress::arrow('Run installer setup');
        $result['setup'] = PinGateClient::setup($gateUrl, $token, [
            'db' => $credentials['db'],
            'user' => $credentials['user'],
            'lang' => $credentials['lang'],
            'force' => $force,
        ]);

        return $result;
    }

    /**
     * @param array{db: array<string, string>, user: array<string, string>, lang: string} $settings
     * @return array{db: array<string, string>, user: array<string, string>, lang: string}
     */
    private function collectCredentials(SymfonyStyle $io, array $settings, bool $interactive): array
    {
        $db = $settings['db'];
        $user = $settings['user'];
        $lang = $settings['lang'];

        if ($interactive) {
            $io->section('Database (must be reachable from the host)');
            $db['host'] = (string) $io->ask('DB host', $db['host'] !== '' ? $db['host'] : 'localhost');
            $db['database'] = (string) $io->ask('DB name', $db['database'] !== '' ? $db['database'] : 'pinoox');
            $db['username'] = (string) $io->ask('DB username', $db['username'] !== '' ? $db['username'] : null);
            $passwordDefault = $db['password'] !== '' ? $db['password'] : null;
            $asked = $io->askHidden('DB password' . ($passwordDefault !== null ? ' (leave empty to keep current)' : ''));
            if (is_string($asked) && $asked !== '') {
                $db['password'] = $asked;
            }
            $db['connection'] = (string) $io->choice('DB driver', ['mysql', 'mariadb', 'pgsql', 'sqlsrv'], $db['connection'] !== '' ? $db['connection'] : 'mysql');
            $db['port'] = (string) $io->ask('DB port', $db['port'] !== '' ? $db['port'] : '3306');
            $db['prefix'] = (string) $io->ask('Table prefix', $db['prefix'] !== '' ? $db['prefix'] : 'pin_');
            $db['timezone'] = (string) $io->ask('Timezone', $db['timezone'] !== '' ? $db['timezone'] : '+03:30');

            $io->section('Admin user');
            $user['fname'] = (string) $io->ask('First name', $user['fname'] !== '' ? $user['fname'] : null);
            $user['lname'] = (string) $io->ask('Last name', $user['lname'] !== '' ? $user['lname'] : null);
            $user['email'] = (string) $io->ask('Email', $user['email'] !== '' ? $user['email'] : null);
            $user['username'] = (string) $io->ask('Username', $user['username'] !== '' ? $user['username'] : null);
            $adminPass = $io->askHidden('Password' . ($user['password'] !== '' ? ' (leave empty to keep current)' : ''));
            if (is_string($adminPass) && $adminPass !== '') {
                $user['password'] = $adminPass;
            }
            $lang = (string) $io->choice('Language', ['en', 'fa'], in_array($lang, ['en', 'fa'], true) ? $lang : 'en');
        }

        $merged = ['db' => $db, 'user' => $user, 'lang' => $lang];
        $errors = ProvisionSettings::validate($merged);
        if ($errors !== []) {
            throw new PinrollException(
                "Missing or invalid provision fields:\n- " . implode("\n- ", $errors)
                . "\nFill .pinoox/pinroll.config.php provision, PINROLL_DB_* / PINROLL_ADMIN_* in .env, or run interactively.",
            );
        }

        return $merged;
    }

    private function buildPlatformZip(): string
    {
        PushProgress::arrow('Build platform zip (pinx:build platform)');
        $root = Pinroll::paths()->root();
        $bundle = ReleaseBundle::fromRecipe(
            Pinroll::config(),
            Pinroll::paths(),
            BuiltinBundle::platformCore($root),
        );
        $build = Pinroll::builder()->build($bundle, 'platform');
        $archive = (string) ($build['archive'] ?? '');
        if ($archive === '' || !is_file($archive)) {
            throw new PinrollException('Platform zip build failed.');
        }

        PushProgress::arrow('Built ' . basename($archive));

        return $archive;
    }
}
