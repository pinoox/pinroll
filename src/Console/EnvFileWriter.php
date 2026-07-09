<?php

namespace Pinoox\Pinroll\Console;

final class EnvFileWriter
{
    /**
     * @param array<string, string> $values
     */
    public static function merge(string $path, array $values, string $newKeyComment = '# Pinroll'): void
    {
        $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
        if ($lines === false) {
            $lines = [];
        }

        foreach ($values as $key => $value) {
            $pattern = '/^' . preg_quote($key, '/') . '\s*=/';
            $line = $key . '=' . self::escapeValue($value);
            $found = false;

            foreach ($lines as $index => $existing) {
                if (preg_match($pattern, (string) $existing)) {
                    $lines[$index] = $line;
                    $found = true;

                    break;
                }
            }

            if ($found) {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;

                continue;
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;

            if ($lines !== [] && trim((string) end($lines)) !== '') {
                $lines[] = '';
            }

            $lines[] = $newKeyComment;
            $lines[] = $line;
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
