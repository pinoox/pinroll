<?php

namespace Pinoox\Pinroll\Target;

use Pinoox\Pinroll\Host\HostResolver;
use Pinoox\Pinroll\Support\Config;

/**
 * @deprecated Use HostResolver
 */
final class TargetResolver
{
    private readonly HostResolver $resolver;

    public function __construct(Config $pinrollConfig, ?string $configPath = null)
    {
        $this->resolver = new HostResolver($pinrollConfig, $configPath);
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(string $name, ?string $via = null): array
    {
        return $this->resolver->resolve($name, $via);
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(string $name): array
    {
        return $this->resolver->raw($name);
    }

    /**
     * @return list<string>
     */
    public function transports(string $name): array
    {
        return $this->resolver->transports($name);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return $this->resolver->names();
    }

    public function defaultHostName(): ?string
    {
        return $this->resolver->defaultHostName();
    }

    /**
     * @return array<string, mixed>
     */
    public function loadedConfig(): array
    {
        return $this->resolver->loadedConfig();
    }

    public static function aliasName(string $name): string
    {
        return HostResolver::aliasName($name);
    }
}
