<?php

namespace Pinoox\Pinroll\Target;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Support\Config;
use Pinoox\Pinroll\Support\ConfigFileLoader;
use Pinoox\Pinroll\Support\ProjectPaths;

final class TargetResolver
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
        $config = $this->load();
        $targets = $config['targets'] ?? [];

        if (!is_array($targets) || !isset($targets[$name]) || !is_array($targets[$name])) {
            throw new PinrollException("Pinroll target not found: {$name}");
        }

        $target = $targets[$name];
        $target['name'] = $name;

        return TargetTransport::resolve($target, $via);
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(string $name): array
    {
        $name = self::alias($name);
        $config = $this->load();
        $targets = $config['targets'] ?? [];

        if (!is_array($targets) || !isset($targets[$name]) || !is_array($targets[$name])) {
            throw new PinrollException("Pinroll target not found: {$name}");
        }

        return array_merge(['name' => $name], $targets[$name]);
    }

    /**
     * @return list<string>
     */
    public function transports(string $name): array
    {
        return TargetTransport::names($this->raw($name));
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        $config = $this->load();
        $targets = $config['targets'] ?? [];

        return is_array($targets) ? array_keys($targets) : [];
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
        $this->config = $loaded;

        return $this->config;
    }
}
