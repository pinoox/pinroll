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
        $file = $paths->bundle($bundleName);

        if (!is_file($file)) {
            throw new PinrollException("Bundle recipe not found: {$bundleName}");
        }

        /** @var array<string, mixed> $recipe */
        $recipe = require $file;
        $options = $recipe;

        if ($package !== null && $package !== '') {
            $options = self::injectPackage($options, $package);
        }

        return new self($config, $paths, $options);
    }

    public static function inferFromPackage(string $package): string
    {
        return 'single-app';
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
