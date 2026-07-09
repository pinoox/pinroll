<?php

namespace Pinoox\Pinroll\Console;

final class ConfigTemplate
{
    private const HEADER = <<<'PHP'
<?php

/**
 * Pinroll targets
 *
 * dir  — deploy path relative to FTP/SSH login ('' = login root; often public_html).
 *        Public URL: https://pinoox.com/pingate.php when the site is at domain root.
 *        Subfolder example: 'shop' → https://pinoox.com/shop/pingate.php
 * via  — default transport: ftp | ssh | pinion
 * gate — PinGate for this target (url + token). Shared by all transports for apply / pinion.
 * ftp / ssh / pinion — connection credentials only (no nested gate).
 *
 * Workflow: pinroll:vendor → pinroll:gate → pinroll:push {target} -a
 */

PHP;

    /**
     * @param array<string, array<string, mixed>> $targets
     */
    public static function render(array $targets): string
    {
        $lines = [
            rtrim(self::HEADER),
            '',
            'return [',
            "    'targets' => [",
        ];

        foreach ($targets as $name => $target) {
            $lines = array_merge($lines, self::renderTarget((string) $name, $target));
        }

        $lines[] = '    ],';
        $lines[] = '];';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $target
     * @return list<string>
     */
    private static function renderTarget(string $name, array $target): array
    {
        $via = (string) ($target['via'] ?? 'ftp');
        $dir = var_export((string) ($target['dir'] ?? ''), true);

        $lines = [
            '        ' . var_export($name, true) . ' => [',
            "            'dir' => {$dir},",
            "            'via' => " . var_export($via, true) . ',',
            '',
        ];

        $gate = self::resolveGateBlock($name, $target);
        if ($gate !== null) {
            $lines[] = "            'gate' => [";
            foreach ($gate as $field => $value) {
                $lines[] = '                ' . var_export((string) $field, true) . ' => ' . self::exportField($name, 'pinion', (string) $field, $value) . ',';
            }
            $lines[] = '            ],';
            $lines[] = '';
        } else {
            $lines = array_merge($lines, self::commentedGate($name));
            $lines[] = '';
        }

        foreach (['ftp', 'ssh', 'pinion'] as $transport) {
            $block = is_array($target[$transport] ?? null) ? $target[$transport] : null;

            if ($transport === 'ftp' && $block === null) {
                $block = self::legacyFtpBlock($name, $target);
            }

            if ($transport === 'pinion') {
                // pinion credentials live in top-level gate; skip empty pinion blocks
                if ($block === null || self::isEmptyConnectionBlock($block)) {
                    continue;
                }
            }

            $active = $block !== null;
            $comment = !$active || ($transport !== $via && $transport !== 'ftp');

            if ($block === null) {
                $lines[] = '            // ' . var_export($transport, true) . ' => [';
                $lines = array_merge($lines, self::commentedBlock($name, $transport));
                $lines[] = '            // ],';
                $lines[] = '';

                continue;
            }

            if ($comment && $transport !== 'ftp') {
                $lines[] = '            // ' . var_export($transport, true) . ' => [';
                foreach ($block as $field => $value) {
                    if ($field === 'gate') {
                        continue;
                    }
                    $lines[] = '            //     ' . var_export((string) $field, true) . ' => ' . self::exportField($name, $transport, (string) $field, $value) . ',';
                }
                $lines[] = '            // ],';
                $lines[] = '';

                continue;
            }

            $lines[] = '            ' . var_export($transport, true) . ' => [';
            foreach ($block as $field => $value) {
                if ($field === 'gate') {
                    continue;
                }
                $lines[] = '                ' . var_export((string) $field, true) . ' => ' . self::exportField($name, $transport, (string) $field, $value) . ',';
            }
            $lines[] = '            ],';
            $lines[] = '';
        }

        if (!empty($target['apps']) && is_array($target['apps'])) {
            $apps = array_values(array_filter(array_map('strval', $target['apps'])));
            if ($apps !== []) {
                $lines[] = "            'apps' => [" . implode(', ', array_map(static fn (string $app): string => var_export($app, true), $apps)) . '],';
                $lines[] = '';
            }
        } else {
            $lines[] = "            // 'apps' => ['com_pinoox_manager'],  // omit = all apps in apps/";
            $lines[] = '';
        }

        $lines[] = '        ],';

        return $lines;
    }

    /**
     * @param array<string, mixed> $target
     * @return array<string, mixed>|null
     */
    private static function resolveGateBlock(string $name, array $target): ?array
    {
        if (is_array($target['gate'] ?? null)) {
            return $target['gate'];
        }

        // Migrate legacy ftp.gate / flat fields into top-level when rendering
        if (is_array($target['ftp']['gate'] ?? null)) {
            return $target['ftp']['gate'];
        }

        $url = $target['gate_url'] ?? null;
        $token = $target['token'] ?? null;
        if ($url !== null || $token !== null) {
            return [
                'url' => is_array($url) ? $url : ['_env' => ConfigWriter::envKeyFor($name, 'url', 'pinion'), 'default' => (string) ($url ?? '')],
                'token' => is_array($token) ? $token : ['_env' => ConfigWriter::envKeyFor($name, 'token', 'pinion'), 'default' => (string) ($token ?? '')],
            ];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function commentedGate(string $target): array
    {
        return [
            '            // optional PinGate (pinroll:gate ' . $target . ')',
            "            // 'gate' => [",
            "            //     'url' => env('" . ConfigWriter::envKeyFor($target, 'url', 'pinion') . "', ''),",
            "            //     'token' => env('" . ConfigWriter::envKeyFor($target, 'token', 'pinion') . "', ''),",
            '            // ],',
        ];
    }

    /**
     * @param array<string, mixed> $block
     */
    private static function isEmptyConnectionBlock(array $block): bool
    {
        foreach ($block as $key => $value) {
            if ($key === 'gate') {
                continue;
            }
            if (is_string($value) && $value !== '') {
                return false;
            }
            if (is_array($value) && isset($value['_env'])) {
                $default = $value['default'] ?? '';
                if ($default !== '' && $default !== null) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function commentedBlock(string $target, string $transport): array
    {
        return match ($transport) {
            'ssh' => [
                "            //     'host' => env('" . ConfigWriter::envKeyFor($target, 'host', 'ssh') . "', ''),",
                "            //     'user' => env('" . ConfigWriter::envKeyFor($target, 'user', 'ssh') . "', ''),",
                "            //     'key' => env('" . ConfigWriter::envKeyFor($target, 'key', 'ssh') . "', ''),",
            ],
            'pinion' => [
                "            //     // use top-level gate { url, token } instead",
            ],
            default => [
                "            //     'host' => env('" . ConfigWriter::envKeyFor($target, 'host', 'ftp') . "', ''),",
                "            //     'user' => env('" . ConfigWriter::envKeyFor($target, 'user', 'ftp') . "', ''),",
                "            //     'password' => env('" . ConfigWriter::envKeyFor($target, 'password', 'ftp') . "', ''),",
            ],
        };
    }

    /**
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    private static function legacyFtpBlock(string $name, array $target): array
    {
        return [
            'host' => $target['host'] ?? ['_env' => ConfigWriter::envKeyFor($name, 'host', 'ftp'), 'default' => ''],
            'user' => $target['user'] ?? ['_env' => ConfigWriter::envKeyFor($name, 'user', 'ftp'), 'default' => ''],
            'password' => $target['password'] ?? ['_env' => ConfigWriter::envKeyFor($name, 'password', 'ftp'), 'default' => ''],
        ];
    }

    private static function exportField(string $target, string $transport, string $field, mixed $value): string
    {
        if (is_array($value) && isset($value['_env'])) {
            $envKey = (string) $value['_env'];
            $default = $value['default'] ?? '';

            if ($default === '' || $default === null) {
                return "env('{$envKey}', '')";
            }

            return "env('{$envKey}', " . var_export((string) $default, true) . ')';
        }

        if (is_string($value) && $value !== '' && ConfigWriter::isEnvField($field)) {
            $envKey = ConfigWriter::envKeyFor($target, $field, $transport);

            return "env('{$envKey}', " . var_export($value, true) . ')';
        }

        return var_export($value, true);
    }
}
