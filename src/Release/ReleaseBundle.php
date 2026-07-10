<?php

namespace Pinoox\Pinroll\Release;

use Pinoox\Pinroll\Contract\PathResolverInterface;
use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Support\Config;

final class ReleaseBundle
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        private readonly Config $config,
        private readonly PathResolverInterface $paths,
        private readonly array $options = [],
    ) {
    }

    public static function resolve(
        Config $config,
        PathResolverInterface $paths,
        string $bundleName,
        ?string $package = null,
    ): self {
        $recipe = self::loadRecipe($paths, $bundleName, $package);

        if ($package !== null && $package !== '') {
            $recipe = self::injectPackage($recipe, $package);
        }

        return new self($config, $paths, $recipe);
    }

    /**
     * Auto-detect build recipe from platform layout; optional custom bundle override.
     *
     * @param list<string>|null $packages
     */
    public static function resolveAuto(
        Config $config,
        PathResolverInterface $paths,
        ?string $package = null,
        ?array $packages = null,
        ?string $bundleOverride = null,
    ): self {
        if ($bundleOverride !== null && $bundleOverride !== '') {
            return self::resolve($config, $paths, $bundleOverride, $package);
        }

        if ($package !== null && $package !== '') {
            return self::fromRecipe($config, $paths, BuiltinBundle::forApp($package));
        }

        if (is_array($packages) && $packages !== []) {
            $recipe = count($packages) === 1
                ? BuiltinBundle::forApp($packages[0])
                : BuiltinBundle::forApps($packages);

            return self::fromRecipe($config, $paths, $recipe);
        }

        $profile = PlatformProfile::fromRoot($paths->root());

        if ($profile->layout() === PlatformProfile::LAYOUT_PINX_ROOT) {
            return self::fromRecipe($config, $paths, BuiltinBundle::forPinxRoot());
        }

        return self::fromRecipe($config, $paths, BuiltinBundle::forApp($profile->defaultPackage()));
    }

    /**
     * @param array<string, mixed> $recipe
     */
    public static function fromRecipe(Config $config, PathResolverInterface $paths, array $recipe): self
    {
        return new self($config, $paths, $recipe);
    }

    public static function inferFromPackage(string $package): string
    {
        return 'app:' . $package;
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadRecipe(PathResolverInterface $paths, string $bundleName, ?string $package): array
    {
        $file = $paths->bundle($bundleName);
        if (is_file($file)) {
            /** @var array<string, mixed> $recipe */
            $recipe = require $file;

            return $recipe;
        }

        $recipe = BuiltinBundle::recipe($paths->root(), $bundleName, $package);
        if ($recipe !== null) {
            return $recipe;
        }

        throw new PinrollException(
            "Build recipe not found: {$bundleName}. "
            . 'Pinroll auto-detects from apps/ — use --bundle only for a custom recipe in pinroll/bundles/.',
        );
    }

    public function name(): string
    {
        return (string) ($this->options['name'] ?? 'unknown');
    }

    public function scope(): string
    {
        return (string) ($this->options['scope'] ?? 'app');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function buildSteps(): array
    {
        $steps = $this->options['build'] ?? [];

        return is_array($steps) ? $steps : [];
    }

    /**
     * @return list<string>
     */
    public function order(): array
    {
        $order = $this->options['order'] ?? [];

        return is_array($order) ? array_map('strval', $order) : [];
    }

    /**
     * @return list<string>
     */
    public function exclude(): array
    {
        $exclude = $this->options['exclude'] ?? [];

        return is_array($exclude) ? array_map('strval', $exclude) : [];
    }

    public function dependsCheck(): bool
    {
        return (bool) ($this->options['depends_check'] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->options;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function injectPackage(array $options, string $package): array
    {
        $json = json_encode($options);
        $json = str_replace('{{package}}', $package, (string) $json);

        return json_decode($json, true) ?: $options;
    }
}
