<?php

namespace Pinoox\Pinroll;

use Pinoox\Pinroll\Contract\PathResolverInterface;
use Pinoox\Pinroll\Host\HostConfig;
use Pinoox\Pinroll\Host\HostResolver;
use Pinoox\Pinroll\PinGate\PinGateHttpHandler;
use Pinoox\Pinroll\Release\ReleaseBuilder;
use Pinoox\Pinroll\Rollout\RolloutEngine;
use Pinoox\Pinroll\Support\Config;
use Pinoox\Pinroll\Support\ConfigFileLoader;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\ProjectPaths;
use Pinoox\Pinroll\Target\TargetResolver;
use Pinoox\Pinroll\Transport\TransportResolver;

/**
 * Entry point for the Pinroll release rollout engine.
 */
final class Pinroll
{
    private static ?RolloutEngine $engine = null;
    /** @var array<string, mixed> */
    private static array $config = [];
    private static ?PathResolverInterface $paths = null;

    /**
     * @param array<string, mixed> $config
     */
    public static function configure(array $config = [], ?PathResolverInterface $paths = null): void
    {
        self::$config = $config;
        self::$paths = $paths;
        self::$engine = null;
    }

    public static function boot(?PathResolverInterface $paths = null): void
    {
        $paths = $paths ?? self::paths();
        $configFile = ProjectPaths::configFile($paths);
        $overrides = [];

        if ($configFile !== null && is_file($configFile)) {
            /** @var array<string, mixed> $loaded */
            $loaded = ConfigFileLoader::load($configFile);
            $overrides = HostConfig::engineOverrides($loaded);
        }

        self::configure($overrides, $paths);
    }

    public static function config(): Config
    {
        return new Config(self::paths(), self::$config);
    }

    public static function paths(): PathResolverInterface
    {
        return self::$paths ?? new NativePathResolver();
    }

    public static function hosts(?string $configPath = null): HostResolver
    {
        return new HostResolver(self::config(), $configPath);
    }

    /**
     * @deprecated Use hosts()
     */
    public static function targets(?string $configPath = null): TargetResolver
    {
        return new TargetResolver(self::config(), $configPath);
    }

    public static function builder(): ReleaseBuilder
    {
        return new ReleaseBuilder(self::config(), self::paths());
    }

    public static function transports(): TransportResolver
    {
        return new TransportResolver(self::config());
    }

    public static function engine(): RolloutEngine
    {
        if (self::$engine === null) {
            self::$engine = new RolloutEngine(self::config(), self::paths());
        }

        return self::$engine;
    }

    public static function gate(): PinGateHttpHandler
    {
        return new PinGateHttpHandler(self::config(), self::paths(), self::engine());
    }
}
