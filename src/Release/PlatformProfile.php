<?php

namespace Pinoox\Pinroll\Release;

use Pinoox\Pinroll\Console\ProjectPackages;
use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Support\AppBuildPaths;
use Pinoox\Pinroll\Support\PlatformConfig;

/**
 * Detect platform layout (single / multi / pinx-root) and discover app packages.
 */
final class PlatformProfile
{
    public const LAYOUT_PINX_ROOT = 'pinx-root';
    public const LAYOUT_SINGLE = 'single';
    public const LAYOUT_MULTI = 'multi';

    /**
     * @param list<string> $packages
     */
    public function __construct(
        private readonly string $root,
        private readonly string $layout,
        private readonly array $packages,
    ) {
    }

    public static function fromRoot(string $root): self
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');

        if (is_file($root . '/app.php')) {
            $packages = self::discoverPackages($root);

            return new self($root, self::LAYOUT_PINX_ROOT, $packages);
        }

        $packages = self::discoverPackages($root);

        if ($packages === []) {
            throw new PinrollException(
                'No app packages found. Add apps/com_*/app.php or set apps[] in pinroll.config.php.',
            );
        }

        $layout = count($packages) === 1 ? self::LAYOUT_SINGLE : self::LAYOUT_MULTI;

        return new self($root, $layout, $packages);
    }

    public function root(): string
    {
        return $this->root;
    }

    public function layout(): string
    {
        return $this->layout;
    }

    /**
     * @return list<string>
     */
    public function packages(): array
    {
        return $this->packages;
    }

    public function defaultPackage(): string
    {
        return $this->packages[0] ?? ProjectPackages::defaultPackage($this->root);
    }

    public function isMulti(): bool
    {
        return $this->layout === self::LAYOUT_MULTI;
    }

    public function isSingle(): bool
    {
        return $this->layout === self::LAYOUT_SINGLE || $this->layout === self::LAYOUT_PINX_ROOT;
    }

    /**
     * @return list<string>
     */
    public static function discoverPackages(string $root): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $packages = [];
        $appsDir = $root . '/apps';

        if (is_dir($appsDir)) {
            foreach (scandir($appsDir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || !str_starts_with($entry, 'com_')) {
                    continue;
                }

                if (!self::isEnabledPackage($root, $entry)) {
                    continue;
                }

                $packages[] = $entry;
            }
        }

        foreach (PlatformConfig::externalPackages($root) as $name => $entry) {
            if (!is_string($name) || $name === '' || !str_starts_with($name, 'com_')) {
                continue;
            }

            if (self::isExternalEnabled($entry) && self::isEnabledPackage($root, $name, $entry)) {
                $packages[] = $name;
            }
        }

        $packages = array_values(array_unique($packages));
        sort($packages);

        return $packages;
    }

    /**
     * @param array<string, mixed>|string|null $externalEntry
     */
    private static function isEnabledPackage(string $root, string $package, mixed $externalEntry = null): bool
    {
        $appFile = self::appFilePath($root, $package, $externalEntry);
        if (!is_file($appFile)) {
            return false;
        }

        /** @var array<string, mixed> $config */
        $config = require $appFile;

        return ($config['enable'] ?? true) !== false;
    }

  /**
     * @param array<string, mixed>|string|null $entry
     */
    private static function isExternalEnabled(mixed $entry): bool
    {
        if (is_string($entry)) {
            return true;
        }

        if (!is_array($entry)) {
            return false;
        }

        return ($entry['enabled'] ?? true) !== false;
    }

    /**
     * @param array<string, mixed>|string|null $externalEntry
     */
    private static function appFilePath(string $root, string $package, mixed $externalEntry = null): string
    {
        if ($externalEntry !== null) {
            $path = is_string($externalEntry) ? $externalEntry : (string) ($externalEntry['path'] ?? '');
            if ($path !== '') {
                $resolved = PlatformConfig::resolve($root, $path);

                return rtrim($resolved, '/') . '/app.php';
            }
        }

        return AppBuildPaths::appDir($root, $package) . '/app.php';
    }
}
