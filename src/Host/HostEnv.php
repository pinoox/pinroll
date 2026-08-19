<?php

namespace Pinoox\Pinroll\Host;

/**
 * Overlay PINROLL_* env keys onto a host block. Production also reads unscoped PINROLL_VIA / PINROLL_PATH / …
 */
final class HostEnv
{
    /**
     * @param array<string, mixed> $host
     * @return array<string, mixed>
     */
    public static function overlay(string $name, array $host): array
    {
        $via = self::read($name, 'VIA');
        if ($via !== null) {
            $host['via'] = strtolower($via);
        }

        $path = self::read($name, 'PATH');
        if ($path !== null) {
            $host['deploy_path'] = $path;
            $host['dir'] = $path;
        }

        $webPath = self::read($name, 'WEB_PATH');
        if ($webPath !== null) {
            $host['web_path'] = $webPath;
        }

        $hostname = self::read($name, 'HOSTNAME');
        if ($hostname !== null) {
            $host['hostname'] = $hostname;
        }

        $keep = self::read($name, 'KEEP');
        if ($keep !== null && is_numeric($keep)) {
            $host['keep'] = (int) $keep;
        }

        $store = self::read($name, 'STORE');
        if ($store !== null) {
            $host['store'] = $store;
        }

        $autoClean = self::read($name, 'AUTO_CLEAN');
        if ($autoClean !== null) {
            $host['auto_clean'] = filter_var($autoClean, FILTER_VALIDATE_BOOLEAN);
        }

        $apps = self::read($name, 'APPS');
        if ($apps !== null) {
            $host['apps'] = $apps === ''
                ? []
                : array_values(array_filter(array_map('trim', explode(',', $apps))));
        }

        $url = self::read($name, 'URL');
        $token = self::read($name, 'TOKEN');
        if ($url !== null || $token !== null) {
            $gate = is_array($host['gate'] ?? null) ? $host['gate'] : [];
            if ($url !== null) {
                $gate['url'] = $url;
            }
            if ($token !== null) {
                $gate['token'] = $token;
            }
            $host['gate'] = $gate;
        }

        $ftpHost = self::read($name, 'HOST');
        $ftpUser = self::read($name, 'USER');
        $ftpPassword = self::read($name, 'PASSWORD');
        if ($ftpHost !== null || $ftpUser !== null || $ftpPassword !== null) {
            $ftp = is_array($host['ftp'] ?? null) ? $host['ftp'] : [];
            if ($ftpHost !== null) {
                $ftp['host'] = $ftpHost;
            }
            if ($ftpUser !== null) {
                $ftp['user'] = $ftpUser;
            }
            if ($ftpPassword !== null) {
                $ftp['password'] = $ftpPassword;
            }
            $host['ftp'] = $ftp;
        }

        $sshHost = self::read($name, 'SSH_HOST');
        $sshUser = self::read($name, 'SSH_USER');
        $sshKey = self::read($name, 'SSH_KEY');
        $sshPassword = self::read($name, 'SSH_PASSWORD');
        if ($sshHost !== null || $sshUser !== null || $sshKey !== null || $sshPassword !== null) {
            $ssh = is_array($host['ssh'] ?? null) ? $host['ssh'] : [];
            if ($sshHost !== null) {
                $ssh['host'] = $sshHost;
            }
            if ($sshUser !== null) {
                $ssh['user'] = $sshUser;
            }
            if ($sshKey !== null) {
                $ssh['key'] = $sshKey;
            }
            if ($sshPassword !== null) {
                $ssh['password'] = $sshPassword;
            }
            $host['ssh'] = $ssh;
        }

        $provision = is_array($host['provision'] ?? null) ? $host['provision'] : [];
        $db = is_array($provision['db'] ?? null) ? $provision['db'] : [];
        $user = is_array($provision['user'] ?? null) ? $provision['user'] : [];
        foreach (['HOST' => 'host', 'DATABASE' => 'database', 'USERNAME' => 'username', 'PASSWORD' => 'password', 'CONNECTION' => 'connection', 'PORT' => 'port', 'PREFIX' => 'prefix', 'TIMEZONE' => 'timezone'] as $env => $field) {
            $value = self::read($name, 'DB_' . $env);
            if ($value !== null) {
                $db[$field] = $value;
            }
        }
        foreach (['FNAME' => 'fname', 'LNAME' => 'lname', 'EMAIL' => 'email', 'USERNAME' => 'username', 'PASSWORD' => 'password'] as $env => $field) {
            $value = self::read($name, 'ADMIN_' . $env);
            if ($value !== null) {
                $user[$field] = $value;
            }
        }
        $lang = self::read($name, 'LANG');
        if ($lang !== null) {
            $host['lang'] = $lang;
            $provision['lang'] = $lang;
        }
        if ($db !== []) {
            $provision['db'] = $db;
        }
        if ($user !== []) {
            $provision['user'] = $user;
        }
        if ($provision !== []) {
            $host['provision'] = $provision;
        }

        return $host;
    }

    /**
     * Build a host from env alone when no pinroll.config.php exists.
     *
     * @return array<string, mixed>|null
     */
    public static function synthetic(string $name = 'production'): ?array
    {
        $via = strtolower((string) (self::read($name, 'VIA') ?? 'ftp'));
        $path = self::read($name, 'PATH') ?? 'public_html';
        $url = self::read($name, 'URL') ?? '';
        $token = self::read($name, 'TOKEN') ?? '';
        $host = self::read($name, 'HOST') ?? '';
        $user = self::read($name, 'USER') ?? '';

        if ($url === '' && $token === '' && $host === '' && $user === '') {
            return null;
        }

        $block = [
            'deploy_path' => $path,
            'dir' => $path,
            'via' => $via !== '' ? $via : 'ftp',
            'gate' => [
                'url' => $url,
                'token' => $token,
            ],
            'ftp' => [
                'host' => $host,
                'user' => $user,
                'password' => self::read($name, 'PASSWORD') ?? '',
            ],
        ];

        return self::overlay($name, $block);
    }

    public static function read(string $host, string $field): ?string
    {
        $field = strtoupper($field);
        $slug = strtoupper(preg_replace('/[^a-zA-Z0-9]+/', '_', $host) ?: 'PRODUCTION');
        $keys = ['PINROLL_' . $slug . '_' . $field];

        if (in_array(strtolower($host), ['production', 'prod'], true)) {
            array_unshift($keys, 'PINROLL_' . $field);
        }

        foreach ($keys as $key) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
