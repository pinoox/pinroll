<?php

namespace Pinoox\Pinroll\Target;

use Pinoox\Pinroll\Pinroll;

final class PinGateRequestLog
{
    /**
     * @param array<string, mixed> $context
     */
    public static function write(string $method, string $url, array $context): void
    {
        $path = self::path();
        if ($path === null) {
            return;
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        $line = date('c')
            . "\t" . strtoupper($method)
            . "\t" . $url
            . "\t" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
            . "\n";

        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    public static function path(): ?string
    {
        try {
            if (!class_exists(Pinroll::class, false)) {
                return null;
            }

            return Pinroll::config()->storage('pinroll/gate/' . date('Ymd') . '.log');
        } catch (\Throwable) {
            return null;
        }
    }
}
