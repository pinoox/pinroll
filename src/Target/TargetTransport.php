<?php

namespace Pinoox\Pinroll\Target;

use Pinoox\Pinroll\Exception\PinrollException;

/**
 * Multi-transport target config: ftp / ssh / pinion / gate blocks under one target.
 */
final class TargetTransport
{
    /** @var list<string> */
    private const KNOWN = ['ftp', 'ssh', 'pinion', 'gate', 'local'];

    /**
     * @param array<string, mixed> $target
     * @return list<string>
     */
    public static function names(array $target): array
    {
        $names = [];
        foreach (self::KNOWN as $name) {
            if ($name === 'gate') {
                continue;
            }

            if ($name === 'pinion' && TargetGate::isConfigured($target) && (string) ($target['via'] ?? '') === 'pinion') {
                $names[] = 'pinion';

                continue;
            }

            if (self::hasBlock($target, $name)) {
                $names[] = $name;
            }
        }

        if ($names !== []) {
            return array_values(array_unique($names));
        }

        if ((string) ($target['transport'] ?? '') !== '' || isset($target['host']) || isset($target['gate_url']) || isset($target['path'])) {
            return [(string) ($target['via'] ?? $target['transport'] ?? 'ftp')];
        }

        return ['ftp'];
    }

    /**
     * @param array<string, mixed> $target
     */
    public static function resolve(array $target, ?string $via = null): array
    {
        $via = strtolower(trim($via ?? (string) ($target['via'] ?? $target['transport'] ?? 'ftp')));
        if ($via === '') {
            $via = 'ftp';
        }

        $available = self::names($target);
        if (!in_array($via, $available, true)) {
            // pinion can use top-level gate without a pinion {} block
            if ($via !== 'pinion' || !TargetGate::isConfigured($target)) {
                throw new PinrollException(
                    'Transport "' . $via . '" is not configured for this target. Available: ' . implode(', ', $available),
                );
            }
        }

        $block = is_array($target[$via] ?? null) ? $target[$via] : [];
        $resolved = [
            'name' => (string) ($target['name'] ?? ''),
            'dir' => (string) ($target['dir'] ?? ''),
            'transport' => $via,
            'via' => (string) ($target['via'] ?? $via),
            'host' => (string) ($block['host'] ?? $target['host'] ?? ''),
            'user' => (string) ($block['user'] ?? $target['user'] ?? ''),
            'password' => (string) ($block['password'] ?? $target['password'] ?? ''),
            'key' => (string) ($block['key'] ?? $target['key'] ?? ''),
            'gate_url' => (string) ($block['url'] ?? $block['gate_url'] ?? $target['gate_url'] ?? ''),
            'token' => (string) ($block['token'] ?? $target['token'] ?? ''),
            'public_key' => (string) ($block['public_key'] ?? $target['public_key'] ?? ''),
            'path' => (string) ($block['path'] ?? $target['path'] ?? ''),
        ];

        if (isset($target['apps'])) {
            $resolved['apps'] = $target['apps'];
        }

        if (isset($target['rules'])) {
            $resolved['rules'] = $target['rules'];
        }

        if (isset($target['package'])) {
            $resolved['package'] = $target['package'];
        }

        $gate = TargetGate::credentials($target);
        if ($gate['url'] !== '') {
            $resolved['gate_url'] = $gate['url'];
        }
        if ($gate['token'] !== '') {
            $resolved['token'] = $gate['token'];
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $target
     */
    private static function hasBlock(array $target, string $name): bool
    {
        if ($name === 'pinion' && TargetGate::isConfigured($target)) {
            return true;
        }

        if (!is_array($target[$name] ?? null)) {
            return false;
        }

        $block = $target[$name];
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

        return false;
    }
}
