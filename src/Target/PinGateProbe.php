<?php

namespace Pinoox\Pinroll\Target;

use Pinoox\Pinroll\Support\HostDir;

final class PinGateProbe
{
    public static function missingDeployMessage(string $hostDir = ''): string
    {
        return 'Run php pinoox pinroll:gate (FTP uploads to ' . HostDir::extractGuidePath($hostDir)
            . ') and set top-level gate { url, token }.';
    }

    public static function htaccessHint(): string
    {
        return ' If response is HTML, add htaccess.snippet rules on the host.';
    }

    /**
     * @return array{ok: bool, deployed: bool, message: string}
     */
    public static function validateStatusResponse(int $status, string $body, string $hostDir = ''): array
    {
        $hint = self::missingDeployMessage($hostDir);
        $entry = HostDir::gateEntryWebPath($hostDir);
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
                    'message' => 'Token invalid — sync .env with gate/pingate.php on host.',
                ];
            }

            // Prefer the real server error (e.g. Pinroll not available / platform root)
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
            ];
        }

        if ($status === 401) {
            return [
                'ok' => false,
                'deployed' => true,
                'message' => 'Token invalid — sync .env with gate/pingate.php on host.',
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
                $hintPack = str_contains($phpError, 'Failed to open stream') || str_contains($phpError, 'phpunit')
                    ? ' Re-run: php pinoox pinroll:vendor — upload a complete vendor.zip (do not strip phpunit).'
                    : '';

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
                'message' => 'Not PinGate JSON. ' . $hint . self::htaccessHint() . $hintPhp,
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
