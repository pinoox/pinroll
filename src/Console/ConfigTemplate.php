<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Support\HostDir;

final class ConfigTemplate
{
    private const HEADER = <<<'PHP'
<?php

/**
 * Pinroll hosts
 *
 * Workflow: pinroll:init → fill .env → pinroll:connect → pinroll:deploy
 * Config path: .pinoox/pinroll.config.php (legacy: pinroll/pinroll.config.php)
 */

PHP;

    private const OVERLAY_HEADER = <<<'PHP'
<?php

/**
 * Pinroll project overlay (gitignored with .pinoox/).
 *
 * Canonical schema: vendor/pinoox/pinroll/config/pinroll.php
 * Override any key. Secrets (token, FTP password) belong here.
 *
 * One token per host — share it with teammates. `pinroll:gate --rotate`
 * uploads a new hash and invalidates everyone else.
 */

PHP;

    /**
     * Short overlay stub — not a dump of the library schema.
     *
     * @param array<string, mixed> $hostPatch
     */
    public static function renderOverlayStub(string $hostName, array $hostPatch = []): string
    {
        $hostName = trim($hostName) !== '' ? trim($hostName) : 'production';
        $lines = [
            rtrim(self::OVERLAY_HEADER),
            '',
            'return [',
            "    'default_host' => " . var_export($hostName, true) . ',',
            '',
            '    // Optional global overrides (uncomment to change library defaults)',
            "    // 'keep' => 3,",
            "    // 'store' => 'remote',",
            '',
            "    'hosts' => [",
        ];
        $lines = array_merge($lines, self::renderOverlayHost($hostName, $hostPatch));
        $lines[] = '    ],';
        $lines[] = '];';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $host
     */
    public static function renderOverlayHostBlock(string $name, array $host = []): string
    {
        return implode("\n", self::renderOverlayHost($name, $host)) . "\n";
    }

    /**
     * @param array<string, mixed> $host
     * @return list<string>
     */
    private static function renderOverlayHost(string $name, array $host = []): array
    {
        $site = '';
        $token = '';
        if (is_array($host['gate'] ?? null)) {
            $site = (string) ($host['gate']['site'] ?? GateUrl::siteFrom((string) ($host['gate']['url'] ?? '')));
            $token = (string) ($host['gate']['token'] ?? '');
        }

        $lines = [
            '        ' . var_export($name, true) . ' => [',
        ];

        if (array_key_exists('deploy_path', $host) || array_key_exists('dir', $host)) {
            $deploy = (string) ($host['deploy_path'] ?? $host['dir'] ?? 'public_html');
            $lines[] = "            'deploy_path' => " . var_export($deploy, true) . ',';
        }

        if (array_key_exists('web_path', $host)) {
            $lines[] = "            'web_path' => " . var_export(HostDir::normalize((string) $host['web_path']), true) . ',';
        }

        if (array_key_exists('via', $host)) {
            $via = (string) ($host['via'] ?: 'ftp');
            $lines[] = "            'via' => " . var_export($via, true) . ',';
        } else {
            $lines[] = "            'via' => 'ftp',";
        }

        $lines[] = "            'gate' => [";
        $lines[] = "                'site' => " . var_export($site, true) . ',  // origin only, e.g. https://pinoox.com';
        $lines[] = "                'token' => " . var_export($token, true) . ',  // shared host token';
        $lines[] = '            ],';

        if (is_array($host['ftp'] ?? null) && trim((string) ($host['ftp']['password'] ?? '')) !== '') {
            $lines[] = "            'ftp' => [";
            $lines[] = "                'password' => " . var_export((string) $host['ftp']['password'], true) . ',';
            $lines[] = '            ],';
        } else {
            $lines[] = "            // 'ftp' => [";
            $lines[] = "            //     'host' => '',";
            $lines[] = "            //     'user' => '',";
            $lines[] = "            //     'password' => '',";
            $lines[] = '            // ],';
        }

        $lines[] = '        ],';
        $lines[] = '';

        return $lines;
    }

    /**
     * @param array<string, array<string, mixed>> $hosts
     * @param array<string, mixed> $globals
     */
    public static function renderHosts(array $hosts, array $globals = []): string
    {
        $globals = array_merge(SampleConfig::globalDefaults(), $globals);
        $lines = [
            rtrim(self::HEADER),
            '',
            'return [',
            '    // Default host when CLI omits the host argument',
            "    'default_host' => " . var_export((string) ($globals['default_host'] ?? 'production'), true) . ',',
            '',
            '    // Global defaults — inherited by all hosts unless overridden per host',
            "    'keep' => (int) env('PINROLL_KEEP', '" . (int) ($globals['keep'] ?? 3) . "'),",
            "    'store' => env('PINROLL_STORE', " . var_export((string) ($globals['store'] ?? 'remote'), true) . "),    // local | remote | both",
            "    'auto_clean' => filter_var(env('PINROLL_AUTO_CLEAN', " . (($globals['auto_clean'] ?? true) ? "'true'" : "'false'") . '), FILTER_VALIDATE_BOOLEAN),   // prune beyond keep: remote incoming + local incoming/pinx export',
            '',
            "    'lang' => env('PINROLL_LANG', 'en'),",
            '',
            '    // First-time host install (pinroll:provision) — same fields as the web installer',
            "    'provision' => [",
            "        'db' => [",
            "            'host' => env('PINROLL_DB_HOST', 'localhost'),",
            "            'database' => env('PINROLL_DB_DATABASE', 'pinoox'),",
            "            'username' => env('PINROLL_DB_USERNAME', ''),",
            "            'password' => env('PINROLL_DB_PASSWORD', ''),",
            "            'connection' => env('PINROLL_DB_CONNECTION', 'mysql'),",
            "            'port' => env('PINROLL_DB_PORT', '3306'),",
            "            'prefix' => env('PINROLL_DB_PREFIX', 'pin_'),",
            "            'timezone' => env('PINROLL_DB_TIMEZONE', '+03:30'),",
            '        ],',
            "        'user' => [",
            "            'fname' => env('PINROLL_ADMIN_FNAME', 'support'),",
            "            'lname' => env('PINROLL_ADMIN_LNAME', 'pinoox'),",
            "            'email' => env('PINROLL_ADMIN_EMAIL', 'info@pinoox.com'),",
            "            'username' => env('PINROLL_ADMIN_USERNAME', 'admin'),",
            "            'password' => env('PINROLL_ADMIN_PASSWORD', '123456'),",
            '        ],',
            '    ],',
            '',
            '    // Extra platform zip rules (merged with platform/build.config.php)',
            "    'build' => [",
            "        'exclude' => [],",
            "        'include' => [],",
            '    ],',
            '',
            "    'hosts' => [",
            '        // Host key = identity (like Deployer alias). No separate labels needed.',
        ];

        foreach ($hosts as $name => $host) {
            $lines = array_merge($lines, self::renderHost((string) $name, $host));
        }

        $lines[] = '    ],';
        $lines[] = '];';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param array<string, array<string, mixed>> $targets
     */
    public static function render(array $targets): string
    {
        return self::renderHosts($targets);
    }

    /**
     * @param array<string, mixed> $host
     */
    public static function renderHostBlock(string $name, array $host): string
    {
        return implode("\n", self::renderHost($name, $host)) . "\n";
    }

    /**
     * @param array<string, mixed> $host
     * @return list<string>
     */
    private static function renderHost(string $name, array $host): array
    {
        $via = (string) ($host['via'] ?? 'ftp');
        if ($via === '') {
            $via = 'ftp';
        }
        $deployDefault = (string) ($host['deploy_path'] ?? $host['dir'] ?? 'public_html');
        if ($deployDefault === '') {
            $deployDefault = 'public_html';
        }
        $webDefault = array_key_exists('web_path', $host)
            ? HostDir::normalize((string) $host['web_path'])
            : '';

        $lines = [
            '        ' . var_export($name, true) . ' => [',
            "            'deploy_path' => " . self::hostEnvCall($name, 'path', $deployDefault) . ',',
            "            'web_path' => " . self::hostEnvCall($name, 'web_path', $webDefault) . ',',
            "            'via' => " . self::hostEnvCall($name, 'via', $via) . ',',
            '',
        ];

        $lines = array_merge($lines, self::renderApps($host));
        $lines[] = '';

        $lines[] = '            // Optional: connection address when it differs from the host name';
        $lines[] = '            // Falls back to ftp.host / ssh.host when omitted';
        $lines[] = "            // 'hostname' => env('" . ConfigWriter::envKeyFor($name, 'host', 'ftp') . "', ''),";
        $lines[] = '';

        $gate = self::resolveGateBlock($name, $host);
        if ($gate !== null) {
            $lines[] = "            'gate' => [";
            foreach ($gate as $field => $value) {
                $lines[] = '                ' . var_export((string) $field, true) . ' => ' . self::exportField($name, 'pinion', (string) $field, $value) . ',';
            }
            $lines[] = '            ],';
        } else {
            $lines = array_merge($lines, self::commentedGate($name));
        }
        $lines[] = '';

        if ($via === 'ftp' || is_array($host['ftp'] ?? null)) {
            $ftp = is_array($host['ftp'] ?? null) ? $host['ftp'] : self::legacyFtpBlock($name, $host);
            $lines[] = "            'ftp' => [";
            foreach ($ftp as $field => $value) {
                if ($field === 'gate') {
                    continue;
                }
                $lines[] = '                ' . var_export((string) $field, true) . ' => ' . self::exportField($name, 'ftp', (string) $field, $value) . ',';
            }
            $lines[] = '            ],';
            $lines[] = '';
        }

        $lines = array_merge($lines, self::renderHooks());
        $lines[] = '';
        $lines = array_merge($lines, self::docblockSsh($name));
        $lines[] = '';
        $lines = array_merge($lines, self::docblockPinion());
        $lines[] = '        ],';
        $lines[] = '';

        return $lines;
    }

    /**
     * @param array<string, mixed> $host
     * @return list<string>
     */
    private static function renderApps(array $host): array
    {
        if (!empty($host['apps']) && is_array($host['apps'])) {
            $apps = array_values(array_filter(array_map('strval', $host['apps'])));
            if ($apps !== []) {
                return [
                    '            // Default app packages for push/install on this host',
                    "            'apps' => [" . implode(', ', array_map(static fn (string $app): string => var_export($app, true), $apps)) . '],',
                ];
            }
        }

        return [
            '            // Default app packages for push/install (pinroll:apps — or pinroll:push will prompt)',
            "            // 'apps' => ['com_pinoox_account'],",
        ];
    }

    /**
     * @return list<string>
     */
    private static function renderHooks(): array
    {
        return [
            '            // Lifecycle hooks (shell commands)',
            "            // 'hooks' => [",
            "            //     'before_push' => ['npm run build'],",
            "            //     'after_push' => [],",
            "            //     'before_install' => ['php pinoox migrate --force'],",
            "            //     'after_install' => ['php pinoox cache:build'],",
            "            //     'before_rollback' => [],",
            "            //     'after_rollback' => [],",
            '            // ],',
        ];
    }

    /**
     * @return list<string>
     */
    private static function docblockSsh(string $name): array
    {
        return [
            '            /**',
            '             * SSH — SFTP upload and remote install',
            '             *',
            "             * Set 'via' => 'ssh' and uncomment:",
            '             *',
            "             * 'ssh' => [",
            "             *     'host' => env('" . ConfigWriter::envKeyFor($name, 'host', 'ssh') . "', ''),",
            "             *     'user' => env('" . ConfigWriter::envKeyFor($name, 'user', 'ssh') . "', ''),",
            "             *     'key'  => env('" . ConfigWriter::envKeyFor($name, 'key', 'ssh') . "', ''),",
            '             * ],',
            '             */',
        ];
    }

    /**
     * @return list<string>
     */
    private static function docblockPinion(): array
    {
        return [
            '            /**',
            '             * Pinion — chunked HTTP upload through PinGate',
            '             *',
            "             * Set 'via' => 'pinion'. Credentials live in gate { site, token } above.",
            '             * Optional one-time FTP bootstrap: pinroll:connect --bootstrap-ftp',
            '             */',
        ];
    }

    /**
     * @param array<string, mixed> $host
     * @return array<string, mixed>|null
     */
    private static function resolveGateBlock(string $name, array $host): ?array
    {
        if (is_array($host['gate'] ?? null)) {
            return $host['gate'];
        }

        if (is_array($host['ftp']['gate'] ?? null)) {
            return $host['ftp']['gate'];
        }

        $url = $host['gate_url'] ?? null;
        $token = $host['token'] ?? null;
        if ($url !== null || $token !== null) {
            return [
                'url' => is_array($url) ? $url : ['_env' => ConfigWriter::envKeyFor($name, 'url', 'pinion'), 'default' => (string) ($url ?? '')],
                'token' => is_array($token) ? $token : ['_env' => ConfigWriter::envKeyFor($name, 'token', 'pinion'), 'default' => (string) ($token ?? '')],
            ];
        }

        return [
            'url' => ['_env' => ConfigWriter::envKeyFor($name, 'url', 'pinion'), 'default' => ''],
            'token' => ['_env' => ConfigWriter::envKeyFor($name, 'token', 'pinion'), 'default' => ''],
        ];
    }

    /**
     * @return list<string>
     */
    private static function commentedGate(string $target): array
    {
        return [
            "            'gate' => [",
            "                'site' => env('" . ConfigWriter::envKeyFor($target, 'site', 'pinion') . "', ''),",
            "                'token' => env('" . ConfigWriter::envKeyFor($target, 'token', 'pinion') . "', ''),",
            '            ],',
        ];
    }

    /**
     * @param array<string, mixed> $host
     * @return array<string, mixed>
     */
    private static function legacyFtpBlock(string $name, array $host): array
    {
        return [
            'host' => $host['host'] ?? ['_env' => ConfigWriter::envKeyFor($name, 'host', 'ftp'), 'default' => ''],
            'user' => $host['user'] ?? ['_env' => ConfigWriter::envKeyFor($name, 'user', 'ftp'), 'default' => ''],
            'password' => $host['password'] ?? ['_env' => ConfigWriter::envKeyFor($name, 'password', 'ftp'), 'default' => ''],
        ];
    }

    private static function hostEnvCall(string $name, string $field, string $default): string
    {
        $key = ConfigWriter::envKeyFor($name, $field);
        if ($default === '') {
            return "env('{$key}', '')";
        }

        return "env('{$key}', " . var_export($default, true) . ')';
    }

    private static function exportField(string $target, string $transport, string $field, mixed $value): string
    {
        if (ConfigWriter::isEnvField($field)) {
            $envKey = is_array($value) && isset($value['_env'])
                ? (string) $value['_env']
                : ConfigWriter::envKeyFor($target, $field, $transport);
            $default = is_array($value) ? ($value['default'] ?? '') : (string) $value;

            if ($default === '' || $default === null) {
                return "env('{$envKey}', '')";
            }

            return "env('{$envKey}', " . var_export((string) $default, true) . ')';
        }

        return var_export($value, true);
    }
}
