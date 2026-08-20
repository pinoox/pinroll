<?php

namespace Pinoox\Pinroll\Target;

use Pinoox\Pinroll\Support\HostDir;

final class PinGateProbe
{
    public static function missingDeployMessage(string $deployPath = ''): string
    {
        return 'Run: php pinoox pinroll:gate (uploads pingate.php into '
            . HostDir::extractGuidePath($deployPath)
            . ') and set PINROLL_*_URL / PINROLL_*_TOKEN.';
    }

    /**
     * @return list<string>
     */
    public static function notJsonFixSteps(
        string $deployPath = '',
        string $webPath = '',
        string $hostName = '',
    ): array {
        $hostArg = $hostName !== '' ? ' ' . $hostName : '';
        $deploy = HostDir::deployRoot($deployPath);
        $deployLabel = $deploy === '.' ? 'FTP/SSH login root' : $deploy . '/';
        $gateUrlExample = $webPath === ''
            ? 'https://your-domain.com/pingate.php?route='
            : 'https://your-domain.com/' . $webPath . '/pingate.php?route=';

        return [
            'The gate URL returned HTML (your site), not PinGate JSON — pingate.php is missing, in the wrong folder, or rewritten.',
            '',
            'Fix:',
            '  1. Upload PinGate into the same FTP folder as index.php (' . $deployLabel . '):',
            '     php pinoox pinroll:gate' . $hostArg . ' -n',
            '  2. Set gate URL to the public site (not the FTP folder name):',
            '     ' . $gateUrlExample,
            '     Subdomain docroot (FTP folder is the domain root): set web_path => \'\' in .pinoox/pinroll.config.php',
            '     URL subdirectory only: set web_path to that path (e.g. \'shop\').',
            '  3. On the host, confirm pingate.php sits next to index.php (FTP list the deploy folder).',
            '  4. If the URL still returns HTML, paste storage/pinroll/htaccess.snippet rules before the front-controller in the host .htaccess.',
            '  5. Re-check: php pinoox pinroll:check' . $hostArg,
            '     or: php pinoox pinroll:connect' . $hostArg . ' --reset',
        ];
    }

    /**
     * @return array{ok: bool, deployed: bool, message: string, hints?: list<string>}
     */
    public static function validateStatusResponse(
        int $status,
        string $body,
        string $deployPath = '',
        ?string $webPath = null,
        string $hostName = '',
    ): array {
        $web = $webPath ?? HostDir::webPath($deployPath);
        $hint = self::missingDeployMessage($deployPath);
        $entry = HostDir::gateEntryWebPath($web);
        $trimmed = trim($body);
        $jsonError = self::jsonError($trimmed);

        if ($jsonError !== null) {
            if (str_contains($jsonError, 'Unknown PinGate route')) {
                return [
                    'ok' => false,
                    'deployed' => false,
                    'message' => 'Wrong gate_url — use /' . $entry . '?route=',
                ];
            }

            if ($status === 401 || str_contains(strtolower($jsonError), 'token') || str_contains(strtolower($jsonError), 'unauthorized')) {
                return [
                    'ok' => false,
                    'deployed' => true,
                    'message' => 'Token invalid — upload storage/pinroll/tokens/{label}.php (php pinoox pinroll:token {label} --push).',
                ];
            }

            return [
                'ok' => false,
                'deployed' => str_contains($jsonError, 'Pinroll not available')
                    || str_contains($jsonError, 'Platform not'),
                'message' => $jsonError,
            ];
        }

        if ($status === 404) {
            return [
                'ok' => false,
                'deployed' => false,
                'message' => 'Not found (404). ' . $hint,
                'hints' => self::notJsonFixSteps($deployPath, $web, $hostName),
            ];
        }

        if ($status === 401) {
            return [
                'ok' => false,
                'deployed' => true,
                'message' => 'Token invalid — upload storage/pinroll/tokens/{label}.php (php pinoox pinroll:token {label} --push).',
            ];
        }

        if ($status === 503 && str_contains($body, 'Service Unavailable') && !str_starts_with($trimmed, '{')) {
            return [
                'ok' => false,
                'deployed' => true,
                'message' => 'Host web server returned 503 for pingate.php (not PinGate JSON). '
                    . 'The site may work but pingate.php crashes or is blocked — check host PHP error log, '
                    . 're-upload storage/pinroll/pingate.php to public_html/ via cPanel/FTP, then retry.',
                'hints' => self::notJsonFixSteps($deployPath, $web, $hostName),
            ];
        }

        if ($status < 200 || $status >= 300) {
            return [
                'ok' => false,
                'deployed' => false,
                'message' => 'HTTP ' . $status . '. ' . $hint,
            ];
        }

        if ($trimmed === '' || $trimmed[0] !== '{') {
            $phpError = self::extractPhpError($body);
            if ($phpError !== null) {
                $hintPack = match (true) {
                    str_contains($phpError, 'Cannot redeclare pinroll_pingate_run') =>
                        ' Re-upload a clean pingate.php: php pinoox pinroll:gate {host}',
                    str_contains($phpError, 'Failed to open stream') || str_contains($phpError, 'phpunit') =>
                        ' Re-run: php pinoox pinroll:vendor — upload a complete vendor.zip (do not strip phpunit).',
                    default => '',
                };

                return [
                    'ok' => false,
                    'deployed' => true,
                    'message' => $phpError . $hintPack,
                ];
            }

            $hintPhp = match (true) {
                str_contains($body, 'Pinroll\\Pinroll') || str_contains($body, 'vendor/pinoox/pinroll') =>
                    ' Deploy the full platform first, then run php pinoox pinroll:gate.',
                str_contains($body, 'Fatal error') || str_contains($body, 'Warning') =>
                    ' PinGate PHP error on host — re-run pinroll:vendor + pinroll:gate.',
                default => '',
            };

            return [
                'ok' => false,
                'deployed' => false,
                'message' => 'Not PinGate JSON — URL returned HTML/site content instead of PinGate.' . $hintPhp,
                'hints' => self::notJsonFixSteps($deployPath, $web, $hostName),
            ];
        }

        $json = json_decode($body, true);
        if (!is_array($json) || ($json['success'] ?? null) !== true) {
            $error = is_array($json) ? (string) ($json['error'] ?? 'error') : 'invalid json';

            return [
                'ok' => false,
                'deployed' => false,
                'message' => str_contains($error, 'Unknown PinGate route')
                    ? 'Wrong gate_url — use /' . $entry . '?route='
                    : 'PinGate error: ' . $error,
            ];
        }

        $data = $json['data'] ?? null;
        if (!is_array($data) || !array_key_exists('status', $data)) {
            return [
                'ok' => false,
                'deployed' => false,
                'message' => 'URL is not PinGate /status.',
            ];
        }

        $platform = is_array($data['platform'] ?? null) ? $data['platform'] : null;
        if (is_array($platform) && ($platform['ok'] ?? null) === false) {
            return [
                'ok' => false,
                'deployed' => true,
                'message' => (string) ($platform['message'] ?? 'Platform not ready for Pinx install.'),
            ];
        }

        return [
            'ok' => true,
            'deployed' => true,
            'message' => 'OK',
        ];
    }

    private static function jsonError(string $trimmed): ?string
    {
        if ($trimmed === '' || $trimmed[0] !== '{') {
            return null;
        }

        $json = json_decode($trimmed, true);
        if (!is_array($json) || ($json['success'] ?? null) === true) {
            return null;
        }

        $error = trim((string) ($json['error'] ?? ''));

        return $error !== '' ? $error : null;
    }

    private static function extractPhpError(string $body): ?string
    {
        $plain = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/\s+/', ' ', $plain) ?? $plain;

        if (preg_match('/((?:Fatal error|Warning|Parse error|Error):\s.+?)(?:\s+in\s+\/|\s*$)/i', $plain, $m)) {
            return trim($m[1]);
        }

        if (preg_match('/Failed to open stream:[^.]+/i', $plain, $m)) {
            return trim($m[0]);
        }

        return null;
    }
}
