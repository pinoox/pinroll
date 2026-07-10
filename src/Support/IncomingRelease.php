<?php

namespace Pinoox\Pinroll\Support;

use Pinoox\Pinroll\Exception\PinrollException;

final class IncomingRelease
{
    /**
     * @return list<array{id: string, path: string, size: int, mtime: int}>
     */
    public static function list(string $incomingDir): array
    {
        if (!is_dir($incomingDir)) {
            return [];
        }

        $items = [];

        foreach (scandir($incomingDir) ?: [] as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $incomingDir . '/' . $file;
            if (!is_file($path)) {
                continue;
            }

            if (!self::isArchive($file)) {
                continue;
            }

            $items[] = [
                'id' => self::idFromFilename($file),
                'path' => $path,
                'size' => (int) filesize($path),
                'mtime' => (int) filemtime($path),
            ];
        }

        usort($items, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

        return $items;
    }

    public static function resolve(string $incomingDir, ?string $deployId = null): string
    {
        $releases = self::list($incomingDir);

        if ($releases === []) {
            throw new PinrollException('No release archive found in ' . $incomingDir . '. Run pinroll:push first.');
        }

        if ($deployId === null || $deployId === '') {
            return $releases[0]['path'];
        }

        foreach ($releases as $release) {
            if ($release['id'] === $deployId || str_contains($release['path'], $deployId)) {
                return $release['path'];
            }
        }

        throw new PinrollException(
            'Release not found: ' . $deployId . '. Use pinroll:install --list (or --local --list on the host).',
        );
    }

    public static function idFromArchive(string $archivePath): string
    {
        return self::idFromFilename(basename($archivePath));
    }

    public static function resolveInstallable(string $archivePath, string $workDir): string
    {
        if (!is_file($archivePath)) {
            throw new PinrollException('Archive not found: ' . $archivePath);
        }

        $lower = strtolower($archivePath);
        if (str_ends_with($lower, '.pinx') || str_ends_with($lower, '.pin')) {
            return $archivePath;
        }

        if (!str_ends_with($lower, '.tar')) {
            throw new PinrollException('Unsupported release archive: ' . basename($archivePath));
        }

        return self::extractPinxFromTar($archivePath, $workDir);
    }

    private static function extractPinxFromTar(string $tarPath, string $workDir): string
    {
        if (!class_exists(\PharData::class)) {
            throw new PinrollException('Phar extension is required to extract release archives.');
        }

        if (!is_dir($workDir)) {
            mkdir($workDir, 0755, true);
        }

        $phar = new \PharData($tarPath);
        $phar->extractTo($workDir, null, true);

        $matches = glob($workDir . '/*.pinx') ?: [];
        if ($matches === []) {
            $matches = glob($workDir . '/**/*.pinx') ?: [];
        }

        if ($matches === []) {
            throw new PinrollException('No .pinx package found inside ' . basename($tarPath));
        }

        usort($matches, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $matches[0];
    }

    private static function isArchive(string $filename): bool
    {
        $lower = strtolower($filename);

        return str_ends_with($lower, '.tar')
            || str_ends_with($lower, '.pinx')
            || str_ends_with($lower, '.pin');
    }

    private static function idFromFilename(string $filename): string
    {
        return preg_replace('/\.(tar|pinx|pin)$/i', '', $filename) ?? $filename;
    }
}
