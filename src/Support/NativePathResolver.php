<?php

namespace Pinoox\Pinroll\Support;

use Pinoox\Pinroll\Contract\PathResolverInterface;

final class NativePathResolver implements PathResolverInterface
{
    public function __construct(
        private readonly ?string $root = null,
    ) {
    }

    public function root(): string
    {
        if ($this->root !== null && $this->root !== '') {
            return rtrim(str_replace('\\', '/', $this->root), '/');
        }

        if (defined('PINOOX_BASE_PATH')) {
            return rtrim(str_replace('\\', '/', (string) PINOOX_BASE_PATH), '/');
        }

        return rtrim(str_replace('\\', '/', (string) getcwd()), '/');
    }

    public function storage(string $relative = ''): string
    {
        $base = $this->root() . '/storage';

        if ($relative === '') {
            return $base;
        }

        return $base . '/' . ltrim(str_replace('\\', '/', $relative), '/');
    }

    public function config(string $name): string
    {
        return ProjectPaths::dir($this) . '/' . ltrim($name, '/');
    }

    public function bundle(string $name): string
    {
        return ProjectPaths::bundleFile($this, $name);
    }
}
