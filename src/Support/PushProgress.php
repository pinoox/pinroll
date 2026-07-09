<?php

namespace Pinoox\Pinroll\Support;

/**
 * Live progress sink for pinroll:push (CLI).
 */
final class PushProgress
{
    /** @var (\Closure(string, string): void)|null */
    private static ?\Closure $handler = null;

    /** @var (\Closure(int, int, string): void)|null */
    private static ?\Closure $progressHandler = null;

    private static bool $verbose = false;

    /**
     * @param (\Closure(string, string=): void)|null $handler
     * @param (\Closure(int, int, string): void)|null $progressHandler (current, total, label)
     */
    public static function bind(?\Closure $handler, bool $verbose = false, ?\Closure $progressHandler = null): void
    {
        self::$handler = $handler;
        self::$verbose = $verbose;
        self::$progressHandler = $progressHandler;
    }

    public static function log(string $message, string $style = PushConsole::STYLE_DEFAULT): void
    {
        self::emit($message, $style);
    }

    public static function blank(): void
    {
        self::emit('', PushConsole::STYLE_BLANK);
    }

    public static function arrow(string $message): void
    {
        self::emit($message, PushConsole::STYLE_ARROW);
    }

    public static function success(string $message): void
    {
        self::emit($message, PushConsole::STYLE_SUCCESS);
    }

    public static function warn(string $message): void
    {
        self::emit($message, PushConsole::STYLE_WARN);
    }

    public static function verbose(): bool
    {
        return self::$verbose;
    }

    public static function detail(string $message): void
    {
        if (self::$verbose) {
            self::emit($message, PushConsole::STYLE_MUTED);
        }
    }

    public static function stream(string $line): void
    {
        $line = rtrim($line);
        if ($line === '') {
            return;
        }

        if (self::shouldSkipStreamLine($line)) {
            return;
        }

        self::emit($line, PushConsole::STYLE_STREAM);
    }

    public static function progress(int $current, int $total, string $label = ''): void
    {
        if (self::$progressHandler !== null) {
            self::$progressHandler($current, $total, $label);

            return;
        }

        if ($total <= 0) {
            return;
        }

        if ($current === 1 || $current === $total || $current % 25 === 0) {
            $suffix = $label !== '' ? ' — ' . $label : '';
            self::emit($current . '/' . $total . $suffix, PushConsole::STYLE_MUTED);
        }
    }

    private static function emit(string $message, string $style): void
    {
        self::$handler?->__invoke($message, $style);
    }

    private static function shouldSkipStreamLine(string $line): bool
    {
        $trimmed = ltrim($line);

        return str_starts_with($trimmed, 'Proceed with build?')
            || $trimmed === 'yes'
            || $trimmed === 'no';
    }
}
