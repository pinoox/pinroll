<?php

namespace Pinoox\Pinroll\Host;

use Pinoox\Pinroll\Exception\PinrollException;

/**
 * Multi-transport host config: ftp / ssh / pinion / gate blocks under one host.
 */
final class HostTransport
{
    /** @var list<string> */
    private const KNOWN = ['ftp', 'ssh', 'pinion', 'gate', 'local'];

    /**
     * @param array<string, mixed> $host
     * @return list<string>
     */
    public static function names(array $host): array
    {
        $names = [];
        foreach (self::KNOWN as $name) {
            if ($name === 'gate') {
                continue;
            }

            if ($name === 'pinion' && HostGate::isConfigured($host) && (string) ($host['via'] ?? '') === 'pinion') {
                $names[] = 'pinion';

                continue;
            }

            if (self::hasBlock($host, $name)) {
                $names[] = $name;
            }
        }

        if ($names !== []) {
            return array_values(array_unique($names));
        }

        if ((string) ($host['transport'] ?? '') !== '' || isset($host['host']) || isset($host['gate_url']) || isset($host['path'])) {
            return [(string) ($host['via'] ?? $host['transport'] ?? 'ftp')];
        }

        return ['ftp'];
    }

    /**
     * @param array<string, mixed> $host
     */
    public static function resolve(array $host, ?string $via = null): array
    {
        $via = strtolower(trim($via ?? (string) ($host['via'] ?? $host['transport'] ?? 'ftp')));
        if ($via === '') {
            $via = 'ftp';
        }

        $available = self::names($host);
        if (!in_array($via, $available, true)) {
            if ($via !== 'pinion' || !HostGate::isConfigured($host)) {
                throw new PinrollException(
                    'Transport "' . $via . '" is not configured for this host. Available: ' . implode(', ', $available),
                );
            }
        }

        $block = is_array($host[$via] ?? null) ? $host[$via] : [];
        $deployPath = (string) ($host['deploy_path'] ?? $host['dir'] ?? '');
        $hostname = trim((string) ($host['hostname'] ?? ''));

        $resolved = [
            'name' => (string) ($host['name'] ?? ''),
            'deploy_path' => $deployPath,
            'dir' => $deployPath,
            'hostname' => $hostname,
            'transport' => $via,
            'via' => (string) ($host['via'] ?? $via),
            'host' => $hostname !== '' ? $hostname : (string) ($block['host'] ?? $host['host'] ?? ''),
            'user' => (string) ($block['user'] ?? $host['user'] ?? ''),
            'password' => (string) ($block['password'] ?? $host['password'] ?? ''),
            'key' => (string) ($block['key'] ?? $host['key'] ?? ''),
            'gate_url' => (string) ($block['url'] ?? $block['gate_url'] ?? $host['gate_url'] ?? ''),
            'token' => (string) ($block['token'] ?? $host['token'] ?? ''),
            'public_key' => (string) ($block['public_key'] ?? $host['public_key'] ?? ''),
            'path' => (string) ($block['path'] ?? $host['path'] ?? ''),
        ];

        foreach (['apps', 'rules', 'hooks', 'keep', 'store', 'auto_clean'] as $key) {
            if (isset($host[$key])) {
                $resolved[$key] = $host[$key];
            }
        }

        if (isset($host['package'])) {
            $resolved['package'] = $host['package'];
        }

        $gate = HostGate::credentials($host);
        if ($gate['url'] !== '') {
            $resolved['gate_url'] = $gate['url'];
        }
        if ($gate['token'] !== '') {
            $resolved['token'] = $gate['token'];
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $host
     */
    private static function hasBlock(array $host, string $name): bool
    {
        if ($name === 'pinion' && HostGate::isConfigured($host)) {
            return true;
        }

        if (!is_array($host[$name] ?? null)) {
            return false;
        }

        $block = $host[$name];
        foreach ($block as $key => $value) {
            if ($key === 'gate') {
                continue;
            }
            if (is_string($value) && $value !== '') {
                return true;
            }
            if (is_array($value) && isset($value['_env'])) {
                return true;
            }
        }

        if ($name === 'ftp' || $name === 'ssh') {
            return isset($block['host']) || isset($block['user']);
        }

        return false;
    }
}
