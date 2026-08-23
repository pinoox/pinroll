<?php

namespace Pinoox\Pinroll\Support;

/**
 * CLI binary shown in hints. Pinx projects must not be told to run `php pinoox`.
 */
final class CliBin
{
    public static function isPinx(): bool
    {
        $invoke = $_ENV['PINOOX_CLI_INVOKE'] ?? $_SERVER['PINOOX_CLI_INVOKE'] ?? getenv('PINOOX_CLI_INVOKE');
        if (is_string($invoke) && strtolower($invoke) === 'pinx') {
            return true;
        }

        $root = defined('PINOOX_BASE_PATH') ? (string) PINOOX_BASE_PATH : (string) getcwd();
        $root = rtrim(str_replace('\\', '/', $root), '/');

        return is_file($root . '/app.php')
            && !is_dir($root . '/apps')
            && (is_file($root . '/bin/pinx') || is_file($root . '/vendor/bin/pinx'));
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
}
