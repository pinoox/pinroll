<?php

namespace Pinoox\Pinroll\Host;

use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\Config;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Target\PinGateClient;
use Pinoox\Pinroll\Support\StorageCleaner;

final class RetentionPolicy
{
    /**
     * @param array<string, mixed> $host Resolved or raw host config
     * @return array{keep: int, store: string, auto_clean: bool}
     */
    public static function settings(array $host, ?Config $config = null): array
    {
        $config = $config ?? Pinroll::config();

        return [
            'keep' => max(0, (int) ($host['keep'] ?? $config->get('keep', 3))),
            'store' => (string) ($host['store'] ?? $config->get('store', 'remote')),
            'auto_clean' => (bool) ($host['auto_clean'] ?? $config->get('auto_clean', true)),
        ];
    }

    /**
     * @param array<string, mixed> $context host name, gate url/token, etc.
     */
    public static function cleanAfterInstall(array $host, array $context = []): ?array
    {
        $settings = self::settings($host);
        if (!$settings['auto_clean'] || $settings['keep'] === 0) {
            return null;
        }

        $store = $settings['store'];
        $options = [
            'keep' => $settings['keep'],
            'dry_run' => false,
        ];

        $results = [];

        if ($store === 'local' || $store === 'both') {
            $results['local'] = (new StorageCleaner(Pinroll::config()))->clean($options);
        }

        if ($store === 'remote' || $store === 'both') {
            $gateUrl = (string) ($context['gate_url'] ?? $host['gate_url'] ?? '');
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
}
