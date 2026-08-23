<?php

namespace Pinoox\Pinroll\Support;

/**
 * CLI binary shown in hints. Pinx projects must not be told to run `php pinoox`.
 */
final class CliBin
{
    public static function isPinx(?string $root = null): bool
    {
        $invoke = $_ENV['PINOOX_CLI_INVOKE'] ?? $_SERVER['PINOOX_CLI_INVOKE'] ?? getenv('PINOOX_CLI_INVOKE');
        if (is_string($invoke) && strtolower($invoke) === 'pinx') {
            return true;
        }

        $root = self::normalizeRoot($root);
        if ($root === '') {
            return false;
        }

        return is_file($root . '/app.php')
            && !is_dir($root . '/apps')
            && (is_file($root . '/bin/pinx') || is_file($root . '/vendor/bin/pinx'));
    }

    /**
     * PHP entry script: bin/pinx in a Pinx app, otherwise ./pinoox.
     */
    public static function executable(?string $root = null): string
    {
        $root = self::normalizeRoot($root);
        if (self::isPinx($root !== '' ? $root : null)) {
            foreach ([$root . '/bin/pinx', $root . '/vendor/bin/pinx'] as $path) {
                if ($path !== '/' && is_file($path)) {
                    return $path;
                }
            }

            return 'pinx';
        }

        if ($root !== '' && is_file($root . '/pinoox')) {
            return $root . '/pinoox';
        }

        return 'pinoox';
    }

    /**
     * True for pincore `pinx:build` or Pinx-root `build` (package .pinx).
     */
    public static function isPinxPackageCommand(string $command, ?string $root = null): bool
    {
        $command = trim($command);
        if (preg_match('/\bpinx:build\b/', $command) === 1) {
            return true;
        }

        return self::isPinx($root) && preg_match('/^build(\s|$)/', $command) === 1;
    }

    /**
     * Map pincore subcommands onto the Pinx CLI (pinx:build → build, drop package args).
     */
    public static function adaptCommand(string $command, ?string $root = null): string
    {
        $command = trim($command);
        if (!self::isPinx($root)) {
            return $command;
        }

        if (preg_match('/^pinx:build(\s|$)/', $command) === 1) {
            $command = preg_replace('/^pinx:build/', 'build', $command, 1) ?? $command;
            $command = preg_replace('/^build\s+[a-zA-Z0-9_]+(\s|$)/', 'build$1', $command) ?? $command;
        }

        if (preg_match('/^fe:build\s+[a-zA-Z0-9_]+(\s|$)/', $command) === 1) {
            $command = preg_replace('/^fe:build\s+[a-zA-Z0-9_]+/', 'fe:build', $command, 1) ?? $command;
        }

        return $command;
    }

    /**
     * Full local shell line to run a Pincore/Pinx subcommand.
     */
    public static function phpLine(string $root, string $command, bool $flushIni = true): string
    {
        $command = self::adaptCommand($command, $root);
        $bin = self::executable($root);
        $ini = $flushIni ? '-d output_buffering=0 -d implicit_flush=1 ' : '';

        return 'php ' . $ini . escapeshellarg($bin) . ' --no-interaction ' . $command;
    }

    public static function bin(): string
    {
        return self::isPinx() ? 'pinx' : 'php pinoox';
    }

    public static function cmd(string $command): string
    {
        $command = trim($command);
        if (str_starts_with($command, 'php pinoox ')) {
            $command = substr($command, strlen('php pinoox '));
        }

        return self::bin() . ' ' . $command;
    }

    public static function rewrite(string $text): string
    {
        if (!self::isPinx()) {
            return $text;
        }

        return str_replace('php pinoox ', 'pinx ', $text);
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    public static function rewriteLines(array $lines): array
    {
        return array_map(self::rewrite(...), $lines);
    }

    private static function normalizeRoot(?string $root): string
    {
        if ($root === null || $root === '') {
            $root = defined('PINOOX_BASE_PATH') ? (string) PINOOX_BASE_PATH : (string) getcwd();
        }

        return rtrim(str_replace('\\', '/', $root), '/');
    }
}
