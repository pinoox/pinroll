<?php

namespace Pinoox\Pinroll\PinGate;

use Pinoox\Pinroll\Bridge\PlatformBootstrap;
use Pinoox\Pinroll\Console\ProvisionSettings;
use Pinoox\Pinroll\Exception\PinrollException;

/**
 * Run the web installer SetupService on the host through PinGate (token already verified).
 */
final class HostSetup
{
    /**
     * @param array<string, mixed> $db
     * @return array<string, mixed>
     */
    public static function checkDb(string $root, array $db): array
    {
        $driver = strtolower(trim((string) ($db['connection'] ?? 'mysql')));
        if (in_array($driver, ['mysql', 'mariadb', ''], true)) {
            return self::pdoProbe($db);
        }

        try {
            PlatformBootstrap::ensure($root);
            self::assertInstallerAvailable();
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => 'Database connection failed from this host: ' . $e->getMessage(),
            ];
        }

        $error = null;
        $ok = \App\com_pinoox_installer\Component\InstallerDatabase::testConnection($db, $error);

        return [
            'ok' => $ok,
            'message' => $ok
                ? 'Database connection succeeded.'
                : ('Database connection failed from this host' . ($error ? ': ' . $error : '.')),
        ];
    }

    /**
     * @param array<string, mixed> $db
     * @return array{ok: bool, message: string}
     */
    public static function pdoProbe(array $db): array
    {
        $host = trim((string) ($db['host'] ?? 'localhost'));
        $port = trim((string) ($db['port'] ?? '3306'));
        $database = trim((string) ($db['database'] ?? ''));
        $username = (string) ($db['username'] ?? '');
        $password = (string) ($db['password'] ?? '');
        $driver = strtolower(trim((string) ($db['connection'] ?? 'mysql')));

        if ($database === '') {
            return ['ok' => false, 'message' => 'Database name is empty.'];
        }

        if (!in_array($driver, ['mysql', 'mariadb', ''], true)) {
            return ['ok' => false, 'message' => 'PDO probe supports mysql/mariadb; got ' . $driver . '.'];
        }

        if (!extension_loaded('pdo_mysql')) {
            return [
                'ok' => false,
                'message' => 'Host PHP is missing the pdo_mysql extension. Enable it in cPanel → Select PHP Version → Extensions.',
            ];
        }

        if ($port === '') {
            $port = '3306';
        }

        $hosts = array_values(array_unique(array_filter([
            $host !== '' ? $host : 'localhost',
            $host === 'localhost' ? '127.0.0.1' : '',
        ])));

        $last = 'Database connection failed from this host.';
        foreach ($hosts as $tryHost) {
            try {
                $dsn = 'mysql:host=' . $tryHost . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4';
                $pdo = new \PDO($dsn, $username, $password, [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_TIMEOUT => 8,
                ]);
                $pdo->query('SELECT 1');

                return [
                    'ok' => true,
                    'message' => 'Database connection succeeded'
                        . ($tryHost !== $host ? ' via ' . $tryHost : '') . '.',
                ];
            } catch (\Throwable $e) {
                $last = self::safeDbError($e->getMessage());
            }
        }

        $hint = '';
        $lower = strtolower($last);
        if (str_contains($lower, 'no such file') || str_contains($lower, '2002')) {
            $hint = ' If the host is localhost, try 127.0.0.1 (TCP instead of a UNIX socket).';
        } elseif (str_contains($lower, 'access denied') || str_contains($lower, '1045')) {
            $hint = ' Username/password were rejected by MySQL on the host (not from your PC).';
        } elseif (str_contains($lower, 'unknown database') || str_contains($lower, '1049')) {
            $hint = ' Create the database in cPanel first, then retry.';
        }

        return ['ok' => false, 'message' => 'Database connection failed from this host: ' . $last . $hint];
    }

    private static function safeDbError(string $message): string
    {
        return trim(preg_replace('/password\s*=\s*\S+/i', 'password=***', $message) ?? $message);
    }

    /**
     * @param array<string, mixed> $db
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function run(string $root, array $db, array $user, ?string $lang = null, bool $force = false): array
    {
        PlatformBootstrap::ensure($root);
        self::assertInstallerAvailable();

        if (!$force && self::installerDisabled($root)) {
            throw new PinrollException(
                'This site is already installed (installer is disabled). Pass force=true to re-run setup.',
                409,
            );
        }

        $settings = ProvisionSettings::resolve(['provision' => ['db' => $db, 'user' => $user, 'lang' => $lang ?? 'en']]);
        $errors = ProvisionSettings::validate($settings);
        if ($errors !== []) {
            throw new PinrollException(implode("\n", $errors), 422);
        }

        $runSetup = static function () use ($settings): void {
            \App\com_pinoox_installer\Component\SetupService::make()->run(
                $settings['db'],
                $settings['user'],
                $settings['lang'],
            );
        };

        if (class_exists(\Pinoox\Portal\App\App::class)
            && \Pinoox\Portal\App\App::exists('com_pinoox_installer')
        ) {
            try {
                \Pinoox\Portal\App\App::meeting('com_pinoox_installer', $runSetup);
            } catch (\Throwable) {
                $runSetup();
            }
        } else {
            $runSetup();
        }

        $htaccess = false;
        try {
            (new \App\com_pinoox_installer\Component\HtaccessManager())->create();
            $htaccess = true;
        } catch (\Throwable) {
        }

        $finish = HostPostInstall::apply($root);

        return [
            'installed' => true,
            'lang' => $settings['lang'],
            'htaccess' => $htaccess,
            'routes' => $finish['routes'],
            'installer_disabled' => $finish['installer_disabled'],
        ];
    }

    private static function assertInstallerAvailable(): void
    {
        if (!class_exists(\App\com_pinoox_installer\Component\SetupService::class)) {
            throw new PinrollException(
                'Installer app is missing on the host. Re-run pinroll:provision (upload platform.zip).',
                503,
            );
        }
    }

    public static function installerDisabled(string $root = ''): bool
    {
        try {
            if (class_exists(\Pinoox\Portal\App\AppEngine::class)
                && \Pinoox\Portal\App\AppEngine::exists('com_pinoox_installer')
            ) {
                $config = \Pinoox\Portal\App\AppEngine::config('com_pinoox_installer');
                if (is_object($config) && method_exists($config, 'get')) {
                    return $config->get('enable') === false;
                }
            }
        } catch (\Throwable) {
        }

        $root = rtrim(str_replace('\\', '/', $root), '/');
        if ($root === '') {
            return false;
        }

        foreach ([
            $root . '/pinker/state/apps/com_pinoox_installer/app.php',
            $root . '/apps/com_pinoox_installer/pinker/app.php',
        ] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $data = include $file;
            if (is_array($data) && array_key_exists('enable', $data) && $data['enable'] === false) {
                return true;
            }
        }

        return false;
    }
}
