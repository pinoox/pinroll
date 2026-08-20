<?php

namespace Pinoox\Pinroll\Host;

use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\Config;
use Pinoox\Pinroll\Support\PushProgress;
use Pinoox\Pinroll\Target\PinGateClient;
use Pinoox\Pinroll\Support\StorageCleaner;

final class RetentionPolicy
{
    /**
     * @param array<string, mixed> $host Resolved or raw host config
     * @return array{keep: int, store: string, auto_clean: bool, clean_before_deploy: bool, stale_days: int}
     */
    public static function settings(array $host, ?Config $config = null): array
    {
        $config = $config ?? Pinroll::config();

        return [
            'keep' => max(0, (int) ($host['keep'] ?? $config->get('keep', 3))),
            'store' => (string) ($host['store'] ?? $config->get('store', 'remote')),
            'auto_clean' => (bool) ($host['auto_clean'] ?? $config->get('auto_clean', true)),
            'clean_before_deploy' => (bool) ($host['clean_before_deploy'] ?? $config->get('clean_before_deploy', true)),
            'stale_days' => max(0, (int) ($host['stale_days'] ?? $config->get('stale_days', 7))),
        ];
    }

    /**
     * Best-effort cleanup before upload/deploy (leftover tmp, old archives, deploy zips).
     *
     * @param array<string, mixed> $host
     * @param array<string, mixed> $context gate_url, token, …
     * @return array<string, mixed>|null
     */
    public static function cleanBeforeDeploy(array $host, array $context = []): ?array
    {
        $settings = self::settings($host);
        if (!$settings['clean_before_deploy']) {
            return null;
        }

        $options = self::cleanupOptions($settings, false);
        $results = [];

        $store = $settings['store'];
        if ($store === 'local' || $store === 'both') {
            $results['local'] = (new StorageCleaner(Pinroll::config()))->clean($options);
        }

        if ($store === 'remote' || $store === 'both') {
            $gateUrl = (string) ($context['gate_url'] ?? $context['url'] ?? $host['gate_url'] ?? '');
            $token = (string) ($context['token'] ?? $host['token'] ?? '');

            if ($gateUrl === '' || $token === '') {
                $gate = HostGate::credentials($host);
                if ($gateUrl === '') {
                    $gateUrl = $gate['url'];
                }
                if ($token === '') {
                    $token = $gate['token'];
                }
            }

            if ($gateUrl !== '' && $token !== '') {
                try {
                    $results['remote'] = PinGateClient::cleanup($gateUrl, $token, $options);
                } catch (\Throwable $e) {
                    $results['remote_error'] = $e->getMessage();
                    PushProgress::detail('Remote cleanup skipped: ' . $e->getMessage());
                }
            }
        }

        self::reportPreDeploy($results, $settings);

        return $results !== [] ? $results : null;
    }

    /**
     * @param array<string, mixed> $context host name, gate url/token, etc.
     */
    public static function cleanAfterInstall(array $host, array $context = []): ?array
    {
        $settings = self::settings($host);
        if (!$settings['auto_clean']) {
            return null;
        }

        $options = self::cleanupOptions($settings, false);
        $store = $settings['store'];
        $results = [];

        if ($store === 'local' || $store === 'both') {
            $results['local'] = (new StorageCleaner(Pinroll::config()))->clean($options);
        }

        if ($store === 'remote' || $store === 'both') {
            $gateUrl = (string) ($context['gate_url'] ?? $context['url'] ?? $host['gate_url'] ?? '');
            $token = (string) ($context['token'] ?? $host['token'] ?? '');

            if ($gateUrl === '' || $token === '') {
                $gate = HostGate::credentials($host);
                if ($gateUrl === '') {
                    $gateUrl = $gate['url'];
                }
                if ($token === '') {
                    $token = $gate['token'];
                }
            }

            if ($gateUrl !== '' && $token !== '') {
                try {
                    $results['remote'] = PinGateClient::cleanup($gateUrl, $token, $options);
                } catch (\Throwable $e) {
                    $results['remote_error'] = $e->getMessage();
                }
            } else {
                $results['remote_skipped'] = 'PinGate token/url missing for remote cleanup';
            }
        }

        return $results !== [] ? $results : null;
    }

    /**
     * @param array{keep: int, store: string, auto_clean: bool, clean_before_deploy: bool, stale_days: int} $settings
     * @return array<string, mixed>
     */
    private static function cleanupOptions(array $settings, bool $dryRun): array
    {
        return [
            'keep' => $settings['keep'],
            'stale_days' => $settings['stale_days'],
            'dry_run' => $dryRun,
            'incoming' => true,
            'tmp' => true,
            'staging' => true,
            'sessions' => true,
            'releases' => true,
            'backups' => true,
            'pinx_export' => true,
            'pinion' => true,
            'deploy_zips' => true,
            'orphans' => true,
        ];
    }

    /**
     * @param array<string, mixed> $results
     * @param array{keep: int, store: string, auto_clean: bool, clean_before_deploy: bool, stale_days: int} $settings
     */
    private static function reportPreDeploy(array $results, array $settings): void
    {
        $parts = [];

        if (is_array($results['local'] ?? null)) {
            $deleted = (int) ($results['local']['files_deleted'] ?? 0);
            if ($deleted > 0) {
                $parts[] = 'local ' . $deleted . ' removed';
            }
        }

        if (is_array($results['remote'] ?? null)) {
            $deleted = (int) ($results['remote']['files_deleted'] ?? 0);
            if ($deleted > 0) {
                $parts[] = 'remote ' . $deleted . ' removed';
            }
        }

        if ($parts === []) {
            return;
        }

        $message = 'Pre-deploy cleanup (keep=' . $settings['keep']
            . ', stale=' . $settings['stale_days'] . 'd) — ' . implode(', ', $parts);
        PushProgress::detail($message);
    }
}
