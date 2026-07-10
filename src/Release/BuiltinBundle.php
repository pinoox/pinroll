<?php

namespace Pinoox\Pinroll\Release;

use Pinoox\Pinroll\Support\PlatformConfig;

/**
 * Built-in build recipes — no pinroll/bundles/*.php required.
 */
final class BuiltinBundle
{
    /**
     * @return array<string, mixed>|null
     */
    public static function recipe(string $root, string $name, ?string $package = null): ?array
    {
        return match ($name) {
            'single-app', 'app' => $package !== null && $package !== ''
                ? self::forApp($package)
                : null,
            'platform-core', 'pincore', 'core' => self::platformCore($root),
            'platform-full', 'platform', 'full' => self::platformFull($root),
            'test-empty', 'test', 'empty' => self::testEmpty(),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function forApp(string $package): array
    {
        return [
            'name' => 'app:' . $package,
            'scope' => 'app',
            'build' => [
                [
                    'type' => 'app',
                    'package' => $package,
                    'command' => 'pinx:build {{package}} --yes --no-ansi',
                ],
            ],
            'depends_check' => true,
        ];
    }

    /**
     * @param list<string> $packages
     * @return array<string, mixed>
     */
    public static function forApps(array $packages): array
    {
        $build = [];
        foreach ($packages as $package) {
            $build[] = [
                'type' => 'app',
                'package' => $package,
                'command' => 'pinx:build ' . $package . ' --yes --no-ansi',
            ];
        }

        return [
            'name' => 'multi-app',
            'scope' => 'multi',
            'build' => $build,
            'order' => $packages,
            'depends_check' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forPinxRoot(): array
    {
        return [
            'name' => 'pinx-root',
            'scope' => 'app',
            'build' => [
                ['type' => 'app', 'command' => 'pinx:build --yes --no-ansi'],
            ],
            'depends_check' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function platformCore(string $root): array
    {
        return [
            'name' => 'platform-core',
            'scope' => 'platform',
            'build' => [
                ['type' => 'platform', 'command' => 'pinx:build platform'],
            ],
            'order' => ['platform'],
            'exclude' => self::excludePatterns($root),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function platformFull(string $root): array
    {
        $profile = PlatformProfile::fromRoot($root);
        $packages = $profile->packages();

        $build = [
            ['type' => 'platform', 'command' => 'pinx:build platform'],
        ];

        foreach ($packages as $package) {
            $build[] = [
                'type' => 'app',
                'package' => $package,
                'command' => 'pinx:build ' . $package . ' --yes --no-ansi',
            ];
        }

        return [
            'name' => 'platform-full',
            'scope' => 'multi',
            'build' => $build,
            'order' => array_merge(['platform'], $packages),
            'exclude' => self::excludePatterns($root),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function testEmpty(): array
    {
        return [
            'name' => 'test-empty',
            'scope' => 'app',
            'build' => [],
            'depends_check' => false,
        ];
    }

    /**
     * @return list<string>
     */
    private static function excludePatterns(string $root): array
    {
        $settings = PlatformConfig::buildSettings($root);
        $exclude = $settings['exclude'] ?? null;

        if (!is_array($exclude) || $exclude === []) {
            return ['storage/', '.env', 'node_modules/', 'theme/*/src'];
        }

        return array_values(array_map('strval', $exclude));
    }
}
