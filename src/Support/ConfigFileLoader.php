<?php

namespace Pinoox\Pinroll\Support;

final class ConfigFileLoader
{
    public static function libraryPath(): string
    {
        return dirname(__DIR__, 2) . '/config/pinroll.php';
    }

    /**
     * @return array<string, mixed>
     */
    public static function load(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        require_once __DIR__ . '/env.php';

        /** @var array<string, mixed> $loaded */
        $loaded = require $path;

        return $loaded;
    }
}
