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
     * @param array<string, array<string, mixed>> $targets
     */
    public static function write(string $path, array $targets): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new PinrollException('Unable to create pinroll directory: ' . $dir);
        }

        if (file_put_contents($path, ConfigTemplate::render($targets)) === false) {
            throw new PinrollException('Unable to write pinroll config: ' . $path);
        }
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
            'token' => 'PINROLL_' . $slug . '_TOKEN',
            'public_key' => 'PINROLL_' . $slug . '_PUBKEY',
            'host' => 'PINROLL_' . $scope . '_HOST',
            'user' => 'PINROLL_' . $scope . '_USER',
            'key' => 'PINROLL_' . $scope . '_KEY',
            'password' => 'PINROLL_' . $scope . '_PASSWORD',
            default => 'PINROLL_' . $scope . '_' . strtoupper($field),
        };
    }

    public static function isEnvField(string $field): bool
    {
        return in_array($field, ['gate_url', 'url', 'token', 'public_key', 'host', 'user', 'key', 'password'], true);
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
