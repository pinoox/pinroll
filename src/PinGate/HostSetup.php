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
        PlatformBootstrap::ensure($root);
        self::assertInstallerAvailable();

        $ok = \App\com_pinoox_installer\Component\InstallerDatabase::testConnection($db);

        return [
            'ok' => $ok,
            'message' => $ok ? 'Database connection succeeded.' : 'Database connection failed from this host.',
        ];
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

        \App\com_pinoox_installer\Component\SetupService::make()->run(
            $settings['db'],
            $settings['user'],
            $settings['lang'],
        );

        $htaccess = false;
        try {
            (new \App\com_pinoox_installer\Component\HtaccessManager())->create();
            $htaccess = true;
        } catch (\Throwable) {
        }

        return [
            'installed' => true,
            'lang' => $settings['lang'],
            'htaccess' => $htaccess,
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
