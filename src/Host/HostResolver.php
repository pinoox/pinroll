<?php

namespace Pinoox\Pinroll\Host;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Support\Config;
use Pinoox\Pinroll\Support\ConfigFileLoader;
use Pinoox\Pinroll\Support\ProjectPaths;

final class HostResolver
{
    /** @var array<string, mixed>|null */
    private ?array $config = null;

    public function __construct(
        private readonly Config $pinrollConfig,
        private readonly ?string $configPath = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(string $name, ?string $via = null): array
    {
        $name = self::alias($name);
        $loaded = $this->load();
        $hosts = HostConfig::hostBlocks($loaded);

        if (!isset($hosts[$name]) || !is_array($hosts[$name])) {
            throw new PinrollException("Pinroll host not found: {$name}");
        }

        $host = HostConfig::mergeHostDefaults($loaded, $hosts[$name]);
        $host['name'] = $name;

        return HostTransport::resolve($host, $via);
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(string $name): array
    {
        $name = self::alias($name);
        $loaded = $this->load();
        $hosts = HostConfig::hostBlocks($loaded);

        if (!isset($hosts[$name]) || !is_array($hosts[$name])) {
            throw new PinrollException("Pinroll host not found: {$name}");
        }

        return array_merge(
            ['name' => $name],
            HostConfig::mergeHostDefaults($loaded, $hosts[$name]),
        );
    }

    /**
     * @return list<string>
     */
    public function transports(string $name): array
    {
        return HostTransport::names($this->raw($name));
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys(HostConfig::hostBlocks($this->load()));
    }

    public function defaultHostName(): ?string
    {
        return HostConfig::defaultHostName($this->load());
    }

    /**
     * @return array<string, mixed>
     */
    public function loadedConfig(): array
    {
        return $this->load();
    }

    public static function aliasName(string $name): string
    {
        return self::alias($name);
    }

    private static function alias(string $name): string
    {
        return match (strtolower($name)) {
            'prod' => 'production',
            'stg' => 'staging',
            default => $name,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $path = $this->configPath ?? ProjectPaths::configFile($this->pinrollConfig->paths());

        if ($path === null || !is_file($path)) {
            throw new PinrollException(
                'Pinroll project config not found. Run `php pinoox pinroll:init` or create pinroll/pinroll.config.php manually.'
            );
        }

        /** @var array<string, mixed> $loaded */
        $loaded = ConfigFileLoader::load($path);
        $this->config = HostConfig::normalizeLoaded($loaded);

        return $this->config;
    }
}
