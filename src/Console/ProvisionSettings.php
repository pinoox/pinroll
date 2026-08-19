<?php

namespace Pinoox\Pinroll\Console;

/**
 * First-time host install credentials: CLI > host/env overlay > config provision > defaults.
 */
final class ProvisionSettings
{
    /**
     * @param array<string, mixed> $host Raw/resolved host (already env-overlaid)
     * @param array<string, mixed> $cli
     * @return array{db: array<string, string>, user: array<string, string>, lang: string}
     */
    public static function resolve(array $host, array $cli = []): array
    {
        $provision = is_array($host['provision'] ?? null) ? $host['provision'] : [];
        $db = array_merge(self::defaultDb(), self::stringMap($provision['db'] ?? []), self::stringMap($cli['db'] ?? []));
        $user = array_merge(self::defaultUser(), self::stringMap($provision['user'] ?? []), self::stringMap($cli['user'] ?? []));
        $lang = strtolower(trim((string) ($cli['lang'] ?? $provision['lang'] ?? $host['lang'] ?? 'en')));
        if (!in_array($lang, ['en', 'fa'], true)) {
            $lang = 'en';
        }

        return [
            'db' => $db,
            'user' => $user,
            'lang' => $lang,
        ];
    }

    /**
     * @param array{db: array<string, string>, user: array<string, string>, lang: string} $settings
     * @return list<string>
     */
    public static function validate(array $settings): array
    {
        $errors = [];
        $db = $settings['db'] ?? [];
        $user = $settings['user'] ?? [];
        $lang = (string) ($settings['lang'] ?? 'en');

        if (trim((string) ($db['host'] ?? '')) === '') {
            $errors[] = 'Database host is required (PINROLL_DB_HOST).';
        }
        if (trim((string) ($db['database'] ?? '')) === '') {
            $errors[] = 'Database name is required (PINROLL_DB_DATABASE).';
        }
        if (trim((string) ($db['username'] ?? '')) === '') {
            $errors[] = 'Database username is required (PINROLL_DB_USERNAME).';
        }

        $connection = strtolower(trim((string) ($db['connection'] ?? 'mysql')));
        if (!in_array($connection, ['mysql', 'mariadb', 'pgsql', 'sqlsrv'], true)) {
            $errors[] = 'Database connection must be mysql, mariadb, pgsql, or sqlsrv.';
        }

        if (mb_strlen(trim((string) ($user['fname'] ?? ''))) < 3) {
            $errors[] = 'Admin first name must be at least 3 characters (PINROLL_ADMIN_FNAME).';
        }
        if (mb_strlen(trim((string) ($user['lname'] ?? ''))) < 3) {
            $errors[] = 'Admin last name must be at least 3 characters (PINROLL_ADMIN_LNAME).';
        }
        $email = trim((string) ($user['email'] ?? ''));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Admin email is required and must be valid (PINROLL_ADMIN_EMAIL).';
        }
        $username = trim((string) ($user['username'] ?? ''));
        if ($username === '' || mb_strlen($username) < 3 || preg_match('/^[A-Za-z0-9_-]+$/', $username) !== 1) {
            $errors[] = 'Admin username must be at least 3 ascii letters, numbers, dashes or underscores (PINROLL_ADMIN_USERNAME).';
        }
        if (mb_strlen((string) ($user['password'] ?? '')) < 6) {
            $errors[] = 'Admin password must be at least 6 characters (PINROLL_ADMIN_PASSWORD).';
        }

        if (!in_array($lang, ['en', 'fa'], true)) {
            $errors[] = 'Language must be en or fa (PINROLL_LANG).';
        }

        return $errors;
    }

    /**
     * @return array<string, string>
     */
    public static function defaultDb(): array
    {
        return [
            'host' => 'localhost',
            'database' => 'pinoox',
            'username' => '',
            'password' => '',
            'connection' => 'mysql',
            'port' => '3306',
            'prefix' => 'pin_',
            'timezone' => '+03:30',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function defaultUser(): array
    {
        return [
            'fname' => '',
            'lname' => '',
            'email' => '',
            'username' => '',
            'password' => '',
        ];
    }

    /**
     * @param mixed $value
     * @return array<string, string>
     */
    private static function stringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) || $item === null) {
                continue;
            }
            $out[$key] = is_scalar($item) ? (string) $item : '';
        }

        return $out;
    }
}
