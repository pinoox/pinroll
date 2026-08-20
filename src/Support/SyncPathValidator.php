<?php

namespace Pinoox\Pinroll\Support;

use Pinoox\Pinroll\Exception\PinrollException;

final class SyncPathValidator
{
    /**
     * Resolve and validate a local directory path.
     */
    public static function localDir(string $path, string $projectRoot): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            throw new PinrollException('Local path is empty.');
        }

        if (!self::isAbsolute($path)) {
            $path = rtrim(str_replace('\\', '/', $projectRoot), '/') . '/' . ltrim($path, '/');
        }

        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            throw new PinrollException('Local directory not found: ' . $path);
        }

        return str_replace('\\', '/', $real);
    }

    /**
     * Remote path relative to deploy root (no leading slash, no ..).
     */
    public static function remoteRelative(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '' || $path === '.') {
            throw new PinrollException('Remote path is empty.');
        }

        if (str_contains($path, '..') || str_starts_with($path, '/')) {
            throw new PinrollException('Remote path must be relative to deploy root (no .. or leading /): ' . $path);
        }

        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                throw new PinrollException('Invalid remote path segment: ' . $path);
            }
        }

        return $path;
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('#^[a-zA-Z]:[/\\\\]#', $path) === 1;
    }
}
