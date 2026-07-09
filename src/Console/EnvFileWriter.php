<?php

namespace Pinoox\Pinroll\Console;

final class EnvFileWriter
{
    /**
     * Merge keys into .env.
     * Existing keys are updated in place.
     * New keys are appended once as a single block under one comment.
     *
     * @param array<string, string> $values
     */
    public static function merge(
        string $path,
        array $values,
        string $blockComment = '# Pinroll — target credentials (FTP / SSH / PinGate)',
    ): void {
        if ($values === []) {
            return;
        }

        $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
        if ($lines === false) {
            $lines = [];
        }

        $updates = [];
        $appends = [];

        foreach ($values as $key => $value) {
            $pattern = '/^' . preg_quote($key, '/') . '\s*=/';
            $line = $key . '=' . self::escapeValue($value);
            $found = false;

            foreach ($lines as $index => $existing) {
                if (preg_match($pattern, (string) $existing)) {
                    $lines[$index] = $line;
                    $found = true;
                    $updates[$key] = $value;

                    break;
                }
            }

            if (!$found) {
                $appends[$key] = $value;
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        if ($appends !== []) {
            // Drop trailing blank lines before appending the block
            while ($lines !== [] && trim((string) end($lines)) === '') {
                array_pop($lines);
            }

            if ($lines !== []) {
                $lines[] = '';
            }

            $lines[] = $blockComment;
            foreach ($appends as $key => $value) {
                $lines[] = $key . '=' . self::escapeValue($value);
            }
        }

        if (file_put_contents($path, implode("\n", $lines) . "\n") === false) {
            throw new \RuntimeException('Unable to write .env file: ' . $path);
        }
    }

    private static function escapeValue(string $value): string
    {
        if (preg_match('/[\s#="\']/', $value)) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }

        return $value;
    }
}
