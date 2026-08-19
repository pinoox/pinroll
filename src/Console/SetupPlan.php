<?php

namespace Pinoox\Pinroll\Console;

/**
 * Which pinroll:setup steps and packages to run.
 */
final class SetupPlan
{
    /** @var list<string> */
    public const STEP_ORDER = ['config', 'migrate', 'seed', 'patch'];

    /**
     * @param array<string, mixed> $options
     * @return list<string>
     */
    public static function steps(array $options): array
    {
        $selected = [];
        foreach (self::STEP_ORDER as $step) {
            if (!empty($options[$step])) {
                $selected[] = $step;
            }
        }

        if ($selected === []) {
            return ['migrate', 'patch'];
        }

        return $selected;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $host
     * @return list<string>
     */
    public static function packages(array $options, array $host = [], ?string $projectRoot = null): array
    {
        $steps = self::steps($options);
        $dbSteps = array_values(array_intersect($steps, ['migrate', 'seed', 'patch']));
        if ($dbSteps === []) {
            return [];
        }

        $apps = self::fromCli($options);
        if ($apps === []) {
            $apps = self::fromHost($host);
        }
        if ($apps === []) {
            $apps = ProjectPackages::list($projectRoot);
        }

        if (empty($options['skip_platform']) && !in_array('platform', $apps, true)) {
            array_unshift($apps, 'platform');
        }

        return array_values(array_unique(array_filter($apps, static fn (string $name): bool => $name !== '')));
    }

    /**
     * @param array<string, mixed> $options
     * @return list<string>
     */
    private static function fromCli(array $options): array
    {
        $app = $options['app'] ?? $options['package'] ?? null;
        if (is_string($app) && $app !== '') {
            return [$app];
        }

        $apps = $options['apps'] ?? null;
        if (is_string($apps) && $apps !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $apps))));
        }
        if (is_array($apps) && $apps !== []) {
            return array_values(array_filter(array_map('strval', $apps)));
        }

        return [];
    }

    /**
     * @param array<string, mixed> $host
     * @return list<string>
     */
    private static function fromHost(array $host): array
    {
        $apps = $host['apps'] ?? null;
        if (is_array($apps) && $apps !== []) {
            return array_values(array_filter(array_map('strval', $apps)));
        }

        $fallback = $host['package'] ?? null;
        if (is_string($fallback) && $fallback !== '') {
            return [$fallback];
        }

        return [];
    }
}
