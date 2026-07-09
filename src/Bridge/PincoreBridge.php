<?php

namespace Pinoox\Pinroll\Bridge;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Release\ReleaseManifest;
use Pinoox\Pinroll\Rollout\RolloutSession;

/**
 * Optional bridge to pincore services when running inside a Pinoox platform.
 */
final class PincoreBridge
{
    public function isAvailable(): bool
    {
        return class_exists(\Pinoox\Portal\Pinx::class)
            || is_file((defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : '') . '/platform/launcher/core-path.php');
    }

    /**
     * @param array{force?: bool} $options
     */
    public function installPackage(string $archivePath, RolloutSession $session, array $options = []): bool
    {
        try {
            $this->bootPlatform();
        } catch (\Throwable $e) {
            $session->addStep('install', 'failed', $e->getMessage());

            return false;
        }

        if (!class_exists(\Pinoox\Portal\Pinx::class)) {
            $session->addStep('install', 'skipped', 'Pincore not available');

            return false;
        }

        try {
            $installer = \Pinoox\Portal\Pinx::installer()->onStep(
                static function (string $step, string $status, string $message) use ($session): void {
                    $session->addStep('install:' . $step, $status, $message);
                },
            );

            $result = $installer->install($archivePath, [
                'force' => !empty($options['force']),
            ]);

            return (bool) ($result->success ?? false);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (str_contains(strtolower($message), 'corrupt zip') || str_contains(strtolower($message), 'zip')) {
                $message .= ' — release must be a .pinx package (not a .tar wrapper). '
                    . 'Re-push with updated pinroll, or extract .pinx from the .tar on the host.';
            }
            $session->addStep('install', 'failed', $message);

            return false;
        }
    }

    private function bootPlatform(): void
    {
        $root = defined('PINOOX_BASE_PATH') ? (string) PINOOX_BASE_PATH : '';
        if ($root === '') {
            throw new PinrollException('PINOOX_BASE_PATH is not defined.');
        }

        PlatformBootstrap::ensure($root);
    }

    public function rollbackMigrations(RolloutSession $session): void
    {
        if (!class_exists(\Pinoox\Component\Migration\Migrator::class)) {
            return;
        }

        try {
            (new \Pinoox\Component\Migration\Migrator('platform'))->rollback();
            $session->addStep('migrate:rollback', 'ok', 'Platform migrations rolled back');
        } catch (\Throwable $e) {
            $session->addStep('migrate:rollback', 'failed', $e->getMessage());
        }
    }

    public function checkDatabase(): bool
    {
        if (!class_exists(\Pinoox\Portal\Database\DB::class)) {
            return true;
        }

        try {
            \Pinoox\Portal\Database\DB::connection()->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function checkStorageWritable(): bool
    {
        if (!defined('PINOOX_BASE_PATH')) {
            return true;
        }

        $storage = PINOOX_BASE_PATH . '/storage';

        return is_dir($storage) && is_writable($storage);
    }

    public function runPostInstall(ReleaseManifest $manifest, RolloutSession $session): void
    {
        $commands = $manifest->deploy()['post_install'] ?? [];
        if (!is_array($commands)) {
            return;
        }

        foreach ($commands as $command) {
            $command = (string) $command;
            $this->runCli($command, $session);
        }
    }

    private function runCli(string $command, RolloutSession $session): void
    {
        $root = defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd();
        $pinoox = is_file($root . '/pinoox') ? $root . '/pinoox' : 'pinoox';
        $full = 'php ' . escapeshellarg($pinoox) . ' ' . $command;
        exec($full . ' 2>&1', $output, $code);
        $session->addStep('post_install', $code === 0 ? 'ok' : 'failed', $command);
    }
}
