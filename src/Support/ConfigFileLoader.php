<?php

namespace Pinoox\Pinroll\Support;

final class ConfigFileLoader
{
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
