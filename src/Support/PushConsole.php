<?php

namespace Pinoox\Pinroll\Support;

final class PushConsole
{
    public const STYLE_DEFAULT = 'default';
    public const STYLE_BLANK = 'blank';
    public const STYLE_HEADER = 'header';
    public const STYLE_PLAN = 'plan';
    public const STYLE_PLAN_ITEM = 'plan_item';
    public const STYLE_STEP = 'step';
    public const STYLE_SUCCESS = 'success';
    public const STYLE_ARROW = 'arrow';
    public const STYLE_MUTED = 'muted';
    public const STYLE_WARN = 'warn';
    public const STYLE_STREAM = 'stream';
    public const STYLE_RAW = 'raw';

    public static function format(string $message, string $style = self::STYLE_DEFAULT): string
    {
        return match ($style) {
            self::STYLE_BLANK => '',
            self::STYLE_RAW => $message,
            self::STYLE_HEADER => '<fg=blue;options=bold>▸ ' . self::escape($message) . '</>',
            self::STYLE_PLAN => '<fg=blue;options=bold>' . self::escape($message) . '</>',
            self::STYLE_PLAN_ITEM => '  <fg=gray>' . self::escape($message) . '</>',
            self::STYLE_STEP => '<fg=cyan;options=bold>' . self::escape($message) . '</>',
            self::STYLE_SUCCESS => '  <fg=green;options=bold>✓</> <info>' . self::escape($message) . '</>',
            self::STYLE_ARROW => '  <fg=yellow>→</> <comment>' . self::escape($message) . '</>',
            self::STYLE_MUTED => '<fg=gray>' . self::escape($message) . '</>',
            self::STYLE_WARN => '<fg=yellow>…</> <fg=yellow>' . self::escape($message) . '</>',
            self::STYLE_STREAM => '  <fg=gray>' . $message . '</>',
            default => self::escape($message),
        };
    }

    /**
     * @param list<string> $labels
     */
    public static function formatStepBadge(int $current, int $total, string $label): string
    {
        $badge = '<fg=magenta;options=bold>[' . $current . '/' . $total . ']</>';
        $title = '<fg=cyan;options=bold>' . self::escape($label) . '</>';

        return $badge . ' ' . $title;
    }

    private static function escape(string $message): string
    {
        return htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
