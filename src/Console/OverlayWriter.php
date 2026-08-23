<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\ProjectPaths;

/**
 * Patch .pinoox/pinroll.config.php in place. Never dumps the library schema.
 */
final class OverlayWriter
{
    /**
     * @param array<string, mixed> $hostPatch
     */
    public static function patch(string $path, string $hostName, array $hostPatch): void
    {
        $hostName = InitService::normalizeHostName($hostName);

        if (!is_file($path)) {
            self::writeStub($path, $hostName, $hostPatch);

            return;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new PinrollException('Unable to read pinroll overlay: ' . $path);
        }

        $hostPattern = '/^\s+' . preg_quote(var_export($hostName, true), '/') . '\s*=>/m';
        if (!preg_match($hostPattern, $contents)) {
            ConfigWriter::addHost($path, $hostName, $hostPatch);
            $contents = (string) file_get_contents($path);
        }

        $lines = explode("\n", (string) preg_replace("/\n$/", '', $contents));
        $range = self::hostRange($lines, $hostName);
        if ($range === null) {
            throw new PinrollException('Unable to locate host "' . $hostName . '" in overlay.');
        }

        [$start, $end] = $range;

        foreach ($hostPatch as $key => $value) {
            if (($key === 'gate' || $key === 'ftp') && is_array($value)) {
                $lines = self::upsertNested($lines, $start, $end, (string) $key, $value, 12);
                $range = self::hostRange($lines, $hostName);
                if ($range === null) {
                    throw new PinrollException('Unable to locate host "' . $hostName . '" in overlay.');
                }
                [$start, $end] = $range;

                continue;
            }

            if (is_array($value)) {
                continue;
            }

            $lines = self::upsertScalar($lines, $start, $end, (string) $key, $value, 12);
            $range = self::hostRange($lines, $hostName);
            if ($range === null) {
                throw new PinrollException('Unable to locate host "' . $hostName . '" in overlay.');
            }
            [$start, $end] = $range;
        }

        self::writePath($path, implode("\n", $lines) . "\n");
    }

    /**
     * @param array<string, mixed> $hostPatch
     */
    public static function writeStub(string $path, string $hostName, array $hostPatch = []): void
    {
        self::writePath($path, ConfigTemplate::renderOverlayStub($hostName, $hostPatch));
    }

    /**
     * Persist site + token (and optional FTP password) into the overlay.
     * Writes .env only when there is no overlay yet and .env already has PINROLL_* gate keys.
     *
     * @return string Path written (overlay or .env)
     */
    public static function persistGate(
        string $projectRoot,
        string $hostName,
        string $site,
        string $token,
        ?string $ftpPassword = null,
        ?string $label = null,
    ): string {
        $paths = new NativePathResolver($projectRoot);
        $preferred = ProjectPaths::preferredConfigFile($paths);
        $legacy = ProjectPaths::legacyConfigFile($paths);
        $overlayPath = is_file($legacy) && !is_file($preferred) ? $legacy : $preferred;
        $hasOverlay = is_file($preferred) || is_file($legacy);

        // Always persist into overlay. Never rewrite .env — PINROLL_* there already wins at runtime.
        $gate = [
            'site' => GateUrl::siteFrom($site),
            'token' => $token,
        ];
        if ($label !== null && $label !== '') {
            $gate['label'] = $label;
        }
        $patch = ['gate' => $gate];
        if ($ftpPassword !== null && $ftpPassword !== '') {
            $patch['ftp'] = ['password' => $ftpPassword];
        }
        self::patch($overlayPath, $hostName, $patch);

        return $overlayPath;
    }

    /**
     * @param list<string> $keys
     */
    private static function envHasAny(string $path, array $keys): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return false;
        }

        foreach ($keys as $key) {
            $pattern = '/^' . preg_quote($key, '/') . '\s*=/';
            foreach ($lines as $line) {
                if (preg_match($pattern, (string) $line)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<string> $lines
     * @return array{0: int, 1: int}|null
     */
    private static function hostRange(array $lines, string $hostName): ?array
    {
        $pattern = '/^\s+' . preg_quote(var_export($hostName, true), '/') . '\s*=>/';
        $start = null;
        foreach ($lines as $index => $line) {
            if (preg_match($pattern, $line)) {
                $start = $index;
                break;
            }
        }

        if ($start === null) {
            return null;
        }

        return [$start, self::blockEnd($lines, $start)];
    }

    /**
     * @param list<string> $lines
     */
    private static function blockEnd(array $lines, int $start): int
    {
        $depth = 0;
        $started = false;
        $count = count($lines);

        for ($i = $start; $i < $count; $i++) {
            $depth += substr_count($lines[$i], '[') - substr_count($lines[$i], ']');
            if (str_contains($lines[$i], '[')) {
                $started = true;
            }
            if ($started && $depth <= 0) {
                return $i;
            }
        }

        return $count - 1;
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function upsertScalar(array $lines, int $start, int $end, string $key, mixed $value, int $indent): array
    {
        $pad = str_repeat(' ', $indent);
        $pattern = '/^' . preg_quote($pad, '/') . "'" . preg_quote($key, '/') . "'\s*=>/";
        $replacement = $pad . var_export($key, true) . ' => ' . var_export($value, true) . ',';

        for ($i = $start + 1; $i < $end; $i++) {
            if (preg_match($pattern, $lines[$i])) {
                $lines[$i] = $replacement;

                return $lines;
            }
        }

        array_splice($lines, $end, 0, [$replacement]);

        return $lines;
    }

    /**
     * @param list<string> $lines
     * @param array<string, mixed> $fields
     * @return list<string>
     */
    private static function upsertNested(array $lines, int $start, int $end, string $key, array $fields, int $indent): array
    {
        $pad = str_repeat(' ', $indent);
        $openPattern = '/^' . preg_quote($pad, '/') . "'" . preg_quote($key, '/') . "'\s*=>\s*\[/";

        $blockStart = null;
        for ($i = $start + 1; $i < $end; $i++) {
            if (preg_match($openPattern, $lines[$i])) {
                $blockStart = $i;
                break;
            }
        }

        if ($blockStart === null) {
            $innerPad = str_repeat(' ', $indent + 4);
            $block = [$pad . var_export($key, true) . ' => ['];
            foreach ($fields as $field => $value) {
                if (is_array($value)) {
                    continue;
                }
                $block[] = $innerPad . var_export((string) $field, true) . ' => ' . var_export($value, true) . ',';
            }
            $block[] = $pad . '],';
            array_splice($lines, $end, 0, $block);

            return $lines;
        }

        $blockEnd = self::blockEnd($lines, $blockStart);
        foreach ($fields as $field => $value) {
            if (is_array($value)) {
                continue;
            }
            $lines = self::upsertScalar($lines, $blockStart, $blockEnd, (string) $field, $value, $indent + 4);
            $blockEnd = self::blockEnd($lines, $blockStart);
        }

        return $lines;
    }

    private static function writePath(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new PinrollException('Unable to create Pinroll config directory: ' . $dir);
        }

        if (file_put_contents($path, $contents) === false) {
            throw new PinrollException('Unable to write pinroll overlay: ' . $path);
        }
    }
}
