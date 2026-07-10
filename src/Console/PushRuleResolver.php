<?php

namespace Pinoox\Pinroll\Console;

/**
 * Resolves deploy parts from target rules + CLI flags (-all, -app, -vendor, -theme).
 */
final class PushRuleResolver
{
    /** @var list<string> */
    public const DEFAULT_PARTS = ['app'];

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $cli
     * @return array{
     *     rule: string,
     *     parts: list<string>,
     *     apps: list<string>,
     *     vendor: bool,
     *     theme: bool,
     *     app: bool
     * }
     */
    public static function resolve(array $target, array $cli = []): array
    {
        $rules = self::rules($target);
        $apps = self::apps($target, $cli);

        $ruleName = (string) ($cli['rule'] ?? '');
        $parts = self::partsFromCli($cli, $rules, $ruleName);

        return [
            'rule' => $ruleName !== '' ? $ruleName : self::ruleLabel($parts),
            'parts' => $parts,
            'apps' => $apps,
            'vendor' => in_array('vendor', $parts, true),
            'theme' => in_array('theme', $parts, true),
            'app' => in_array('app', $parts, true),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function rules(array $target): array
    {
        $rules = $target['rules'] ?? null;

        if (!is_array($rules) || $rules === []) {
            return [
                'all' => ['app', 'vendor', 'theme'],
                'app' => ['app'],
                'vendor' => ['vendor'],
                'theme' => ['theme'],
            ];
        }

        $normalized = [];
        foreach ($rules as $name => $parts) {
            if (!is_string($name) || !is_array($parts)) {
                continue;
            }

            $normalized[$name] = array_values(array_filter(array_map('strval', $parts)));
        }

        return $normalized !== [] ? $normalized : ['app' => self::DEFAULT_PARTS];
    }

    /**
     * @return list<string>
     */
    public static function ruleNames(array $target): array
    {
        return array_keys(self::rules($target));
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $cli
     * @return list<string>
     */
    private static function apps(array $target, array $cli): array
    {
        $app = $cli['app'] ?? $cli['package'] ?? null;
        if (is_string($app) && $app !== '') {
            return [$app];
        }

        $appsList = $cli['apps'] ?? null;
        if (is_string($appsList) && $appsList !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $appsList))));
        }
        if (is_array($appsList) && $appsList !== []) {
            return array_values(array_filter(array_map('strval', $appsList)));
        }

        $apps = $target['apps'] ?? null;
        if (!is_array($apps) || $apps === []) {
            $fallback = $target['package'] ?? null;
            if (is_string($fallback) && $fallback !== '') {
                return [$fallback];
            }

            return [];
        }

        return array_values(array_filter(array_map('strval', $apps)));
    }

    /**
     * @param array<string, mixed> $cli
     * @param array<string, list<string>> $rules
     * @return list<string>
     */
    private static function partsFromCli(array $cli, array $rules, string $ruleName): array
    {
        if (!empty($cli['all'])) {
            return $rules['all'] ?? ['app', 'vendor', 'theme'];
        }

        if ($ruleName !== '') {
            if (!isset($rules[$ruleName])) {
                throw new \InvalidArgumentException('Unknown rule "' . $ruleName . '".');
            }

            return $rules[$ruleName];
        }

        $parts = [];
        foreach (['vendor', 'theme'] as $part) {
            if (!empty($cli[$part])) {
                $parts[] = $part;
            }
        }

        $hasAppPackage = (is_string($cli['app'] ?? null) && $cli['app'] !== '')
            || (is_string($cli['package'] ?? null) && $cli['package'] !== '')
            || !empty($cli['apps'])
            || !empty($cli['all']);

        if ($parts === [] || $hasAppPackage) {
            $parts[] = 'app';
        }

        if ($parts !== []) {
            return array_values(array_unique($parts));
        }

        return $rules['app'] ?? self::DEFAULT_PARTS;
    }

    /**
     * @param list<string> $parts
     */
    private static function ruleLabel(array $parts): string
    {
        sort($parts);

        return implode('+', $parts);
    }
}
