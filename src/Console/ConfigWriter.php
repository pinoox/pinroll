<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Exception\PinrollException;

final class ConfigWriter
{
    /**
     * @param array<string, mixed> $setup
     * @param array<string, array<string, mixed>> $targets
     */
    public static function writeProject(string $path, array $setup, array $targets): void
    {
        unset($setup);
        self::write($path, $targets);
    }

    /**
     * @param array<string, array<string, mixed>> $hosts
     * @param array<string, mixed> $globals
     */
    public static function writeHosts(string $path, array $hosts, array $globals = []): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new PinrollException('Unable to create Pinroll config directory: ' . $dir);
        }

        if (file_put_contents($path, ConfigTemplate::renderHosts($hosts, $globals)) === false) {
            throw new PinrollException('Unable to write pinroll config: ' . $path);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $targets
     */
    public static function write(string $path, array $targets): void
    {
        self::writeHosts($path, $targets, SampleConfig::globalDefaults());
    }

    /**
     * @param array<string, mixed> $setup
     * @param array<string, array<string, mixed>> $targets
     */
    public static function renderProject(array $setup, array $targets): string
    {
        unset($setup);

        return self::render($targets);
    }

    /**
     * @param array<string, array<string, mixed>> $targets
     */
    public static function render(array $targets): string
    {
        return ConfigTemplate::render($targets);
    }

    private static function exportValue(mixed $value): string
    {
        if (is_array($value) && isset($value['_env'])) {
            $envKey = (string) $value['_env'];
            $default = $value['default'] ?? '';

            if ($default === '' || $default === null) {
                return "env('{$envKey}', '')";
            }

            return "env('{$envKey}', " . var_export((string) $default, true) . ')';
        }

        if (is_array($value) && self::isListOfStrings($value)) {
            return '[' . implode(', ', array_map(static fn (string $item): string => var_export($item, true), $value)) . ']';
        }

        if (is_array($value) && self::isRulesMap($value)) {
            return self::exportRules($value);
        }

        return var_export($value, true);
    }

    /**
     * @param array<mixed> $value
     */
    private static function isListOfStrings(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        if (!array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!is_string($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<mixed> $value
     */
    private static function isRulesMap(array $value): bool
    {
        foreach ($value as $key => $parts) {
            if (!is_string($key) || !is_array($parts)) {
                return false;
            }
        }

        return $value !== [];
    }

    /**
     * @param array<string, list<string>> $rules
     */
    private static function exportRules(array $rules): string
    {
        $lines = ['['];
        $names = array_keys($rules);
        $last = array_key_last($names);

        foreach ($names as $index => $name) {
            $parts = $rules[$name];
            $suffix = $index === $last ? '' : ',';
            $lines[] = '            ' . var_export($name, true) . ' => [' . implode(', ', array_map(static fn (string $p): string => var_export($p, true), $parts)) . ']' . $suffix;
        }
        $lines[] = '        ]';

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    public static function normalizeTarget(string $name, array $target): array
    {
        $normalized = [];

        foreach ($target as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (self::isEnvField($key) && is_string($value)) {
                $normalized[$key] = [
                    '_env' => self::envKeyFor($name, $key),
                    'default' => $value,
                ];
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    public static function envKeyFor(string $target, string $field, ?string $transport = null): string
    {
        $slug = strtoupper(preg_replace('/[^a-zA-Z0-9]+/', '_', $target) ?: 'TARGET');
        $scope = match ($transport) {
            'ssh' => $slug . '_SSH',
            'pinion' => $slug,
            default => $slug,
        };

        return match ($field) {
            'url', 'gate_url' => 'PINROLL_' . $slug . '_URL',
            'site' => self::layoutEnvKey($target, 'site'),
            'token' => 'PINROLL_' . $slug . '_TOKEN',
            'public_key' => 'PINROLL_' . $slug . '_PUBKEY',
            'host' => 'PINROLL_' . $scope . '_HOST',
            'user' => 'PINROLL_' . $scope . '_USER',
            'key' => 'PINROLL_' . $scope . '_KEY',
            'password' => 'PINROLL_' . $scope . '_PASSWORD',
            'via', 'path', 'deploy_path', 'dir', 'web_path' => self::layoutEnvKey($target, $field),
            default => 'PINROLL_' . $scope . '_' . strtoupper($field),
        };
    }

    /**
     * Production uses unscoped PINROLL_VIA / PINROLL_PATH (same as HostEnv overlay).
     */
    private static function layoutEnvKey(string $target, string $field): string
    {
        $suffix = match ($field) {
            'via' => 'VIA',
            'web_path' => 'WEB_PATH',
            'site' => 'SITE',
            default => 'PATH',
        };

        if (in_array(strtolower($target), ['production', 'prod'], true)) {
            return 'PINROLL_' . $suffix;
        }

        $slug = strtoupper(preg_replace('/[^a-zA-Z0-9]+/', '_', $target) ?: 'TARGET');

        return 'PINROLL_' . $slug . '_' . $suffix;
    }

    public static function isEnvField(string $field): bool
    {
        return in_array($field, ['gate_url', 'url', 'site', 'token', 'public_key', 'host', 'user', 'key', 'password'], true);
    }

    /**
     * @param list<string>|null $apps null clears apps (commented placeholder); non-empty list sets apps[]
     */
    /**
     * Append a host block without rewriting existing hosts (preserves their env keys).
     *
     * @param array<string, mixed>|null $host null = SampleConfig::productionHost($hostName)
     */
    public static function addHost(string $path, string $hostName, ?array $host = null): void
    {
        if (!is_file($path)) {
            throw new PinrollException('Pinroll config not found: ' . $path);
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new PinrollException('Unable to read pinroll config: ' . $path);
        }

        $hostPattern = '/^\s+' . preg_quote(var_export($hostName, true), '/') . '\s*=>/m';
        if (preg_match($hostPattern, $contents)) {
            return;
        }

        $block = ConfigTemplate::renderOverlayHostBlock(
            $hostName,
            $host ?? [],
        );

        // Insert before the closing of the hosts array: "    ]," then "];"
        if (!preg_match('/\n    \],\s*\n\];\s*$/', $contents)) {
            throw new PinrollException('Unable to locate hosts array end in pinroll.config.php');
        }

        $updated = preg_replace(
            '/\n    \],\s*\n\];\s*$/',
            "\n" . $block . "    ],\n];\n",
            $contents,
            1,
        );
        if (!is_string($updated) || $updated === $contents) {
            throw new PinrollException('Unable to insert host "' . $hostName . '" into pinroll.config.php');
        }

        if (file_put_contents($path, $updated) === false) {
            throw new PinrollException('Unable to write pinroll config: ' . $path);
        }
    }

    public static function setHostApps(string $path, string $hostName, ?array $apps): void
    {
        if (!is_file($path)) {
            throw new PinrollException('Pinroll config not found: ' . $path);
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new PinrollException('Unable to read pinroll config: ' . $path);
        }

        $replacement = self::hostAppsLines($apps);
        $inHost = false;
        $hostFound = false;
        $appsStart = null;
        $appsEnd = null;
        $viaIndex = null;
        $hostPattern = "/^\s+" . preg_quote(var_export($hostName, true), '/') . "\s*=>/";
        $appsPattern = '/^\s+(\/\/ Default app packages|\'apps\'\s*=>|\/\/\s+\'apps\'\s*=>)/';

        foreach ($lines as $index => $line) {
            if (preg_match($hostPattern, $line)) {
                $inHost = true;
                $hostFound = true;
                continue;
            }

            if ($inHost && preg_match('/^\s+\],/', $line)) {
                break;
            }

            if (!$inHost) {
                continue;
            }

            if (preg_match("/^\s+'via'\s*=>/", $line)) {
                $viaIndex = $index;
            }

            if (preg_match($appsPattern, $line)) {
                if ($appsStart === null) {
                    $appsStart = $index;
                }
                $appsEnd = $index;
            }
        }

        if (!$hostFound) {
            throw new PinrollException("Host \"{$hostName}\" not found in pinroll.config.php.");
        }

        if ($appsStart !== null && $appsEnd !== null) {
            array_splice($lines, $appsStart, $appsEnd - $appsStart + 1, $replacement);
        } elseif ($viaIndex !== null) {
            $insertAt = $viaIndex + 1;
            if (isset($lines[$insertAt]) && trim($lines[$insertAt]) === '') {
                $insertAt++;
            }

            array_splice($lines, $insertAt, 0, array_merge([''], $replacement, ['']));
        } else {
            throw new PinrollException("Host \"{$hostName}\" has no via key — cannot insert apps.");
        }

        if (file_put_contents($path, implode("\n", $lines) . "\n") === false) {
            throw new PinrollException('Unable to write pinroll config: ' . $path);
        }
    }

    /**
     * @param list<string>|null $apps
     * @return list<string>
     */
    private static function hostAppsLines(?array $apps): array
    {
        if ($apps === null || $apps === []) {
            return [
                '            // Default app packages for push/install (pinroll:apps — or pinroll:push will prompt)',
                "            // 'apps' => ['com_pinoox_account'],",
            ];
        }

        $exported = implode(', ', array_map(static fn (string $app): string => var_export($app, true), $apps));

        return [
            '            // Default app packages for push/install on this host',
            "            'apps' => [{$exported}],",
        ];
    }

    public static function setTargetDir(string $path, string $targetName, string $dir): void
    {
        if (!is_file($path)) {
            throw new PinrollException('Pinroll config not found: ' . $path);
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new PinrollException('Unable to read pinroll config: ' . $path);
        }

        $inTarget = false;
        $updated = false;

        foreach ($lines as $index => $line) {
            if (preg_match("/['\"]" . preg_quote($targetName, '/') . "['\"]\s*=>/", $line)) {
                $inTarget = true;
                continue;
            }

            if ($inTarget && preg_match("/^\s+\],/", $line)) {
                break;
            }

            if ($inTarget && preg_match("/^\s+'dir'\s*=>/", $line)) {
                $lines[$index] = "            'dir' => " . var_export($dir, true) . ',';
                $updated = true;
                break;
            }
        }

        if (!$updated) {
            throw new PinrollException("Target \"{$targetName}\" or dir key not found in config.");
        }

        if (file_put_contents($path, implode("\n", $lines) . "\n") === false) {
            throw new PinrollException('Unable to write pinroll config: ' . $path);
        }
    }
}
