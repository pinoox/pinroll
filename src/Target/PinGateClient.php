<?php

namespace Pinoox\Pinroll\Target;

use Pinoox\Pinroll\Console\GateUrl;
use Pinoox\Pinroll\Exception\PinrollException;

final class PinGateClient
{
    /**
     * @return array<string, mixed>
     */
    public static function install(string $gateUrlBase, string $token, string $deployId, array $options = []): array
    {
        $url = GateUrl::route($gateUrlBase, 'install');
        $payload = array_merge(['deploy_id' => $deployId], $options);
        $response = self::request('POST', $url, $token, $payload);

        if (!($response['success'] ?? false)) {
            $response = self::request('POST', GateUrl::route($gateUrlBase, 'apply'), $token, $payload);
        }

        if (!($response['success'] ?? false)) {
            throw new PinrollException((string) ($response['error'] ?? 'PinGate install failed.'));
        }

        $data = $response['data'] ?? [];
        if (!is_array($data)) {
            throw new PinrollException('PinGate install returned invalid response.');
        }

        return $data;
    }

    /**
     * @deprecated Use install()
     */
    public static function apply(string $gateUrlBase, string $token, string $deployId): array
    {
        return self::install($gateUrlBase, $token, $deployId);
    }

    /**
     * @return array<string, mixed>
     */
    public static function status(string $gateUrlBase, string $token): array
    {
        $response = self::request('GET', GateUrl::route($gateUrlBase, 'status'), $token, [], 30);
        if (($response['success'] ?? false) !== true) {
            throw new PinrollException((string) ($response['error'] ?? 'PinGate status failed.'));
        }

        $data = $response['data'] ?? [];
        if (!is_array($data)) {
            throw new PinrollException('PinGate status returned invalid response.');
        }

        return $data;
    }

    /**
     * Re-apply a previous release on the host (Pinx force install).
     *
     * @return array<string, mixed>
     */
    public static function rollback(string $gateUrlBase, string $token, string $deployId = ''): array
    {
        $payload = $deployId !== '' ? ['deploy_id' => $deployId] : [];
        $response = self::request('POST', GateUrl::route($gateUrlBase, 'rollback'), $token, $payload);

        if (!($response['success'] ?? false)) {
            throw new PinrollException((string) ($response['error'] ?? 'PinGate rollback failed.'));
        }

        $data = $response['data'] ?? [];
        if (!is_array($data)) {
            throw new PinrollException('PinGate rollback returned invalid response.');
        }

        return $data;
    }

    /**
     * @return array{history?: list<array<string, mixed>>}
     */
    public static function history(string $gateUrlBase, string $token): array
    {
        $response = self::request('GET', GateUrl::route($gateUrlBase, 'history'), $token);
        $data = $response['data'] ?? $response;

        return is_array($data) ? $data : [];
    }

    /**
     * @return array{releases?: list<array{id: string, path: string, size: int, mtime: int}>}
     */
    public static function incoming(string $gateUrlBase, string $token): array
    {
        $response = self::request('GET', GateUrl::route($gateUrlBase, 'incoming'), $token);

        if (!($response['success'] ?? false) && !isset($response['releases']) && !isset($response['data'])) {
            throw new PinrollException((string) ($response['error'] ?? 'PinGate incoming list failed.'));
        }

        $data = $response['data'] ?? $response;
        if (!is_array($data)) {
            throw new PinrollException('PinGate incoming returned invalid response.');
        }

        return $data;
    }

    /**
     * Extract a previously uploaded vendor.zip on the host (POST /vendor).
     *
     * @param array{zip?: string, delete_zip?: bool} $options
     * @return array<string, mixed>
     */
    public static function extractVendor(string $gateUrlBase, string $token, array $options = []): array
    {
        $response = self::request('POST', GateUrl::route($gateUrlBase, 'vendor'), $token, $options, 180);

        if (!($response['success'] ?? false)) {
            throw new PinrollException((string) ($response['error'] ?? 'PinGate vendor extract failed.'));
        }

        $data = $response['data'] ?? [];
        if (!is_array($data)) {
            throw new PinrollException('PinGate vendor extract returned invalid response.');
        }

        return $data;
    }

    /**
     * Extract a path-sync zip from storage/pinroll/incoming (POST /sync).
     *
     * @param array{deploy_id?: string, target: string, delete_zip?: bool} $options
     * @return array<string, mixed>
     */
    public static function extractSync(string $gateUrlBase, string $token, array $options = []): array
    {
        $response = self::request('POST', GateUrl::route($gateUrlBase, 'sync'), $token, $options, 600);

        if (!($response['success'] ?? false)) {
            throw new PinrollException((string) ($response['error'] ?? 'PinGate path sync extract failed.'));
        }

        $data = $response['data'] ?? [];
        if (!is_array($data)) {
            throw new PinrollException('PinGate sync extract returned invalid response.');
        }

        return $data;
    }

    /**
     * Extract a previously uploaded platform.zip on the host (POST /bootstrap).
     *
     * @param array{force?: bool} $options
     * @return array<string, mixed>
     */
    public static function bootstrap(string $gateUrlBase, string $token, array $options = []): array
    {
        $response = self::request('POST', GateUrl::route($gateUrlBase, 'bootstrap'), $token, $options, 600);

        if (!($response['success'] ?? false)) {
            throw new PinrollException((string) ($response['error'] ?? 'PinGate platform bootstrap failed.'));
        }

        $data = $response['data'] ?? [];
        if (!is_array($data)) {
            throw new PinrollException('PinGate bootstrap returned invalid response.');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $payload db/user/lang/force
     * @return array<string, mixed>
     */
    public static function setup(string $gateUrlBase, string $token, array $payload): array
    {
        $response = self::request('POST', GateUrl::route($gateUrlBase, 'setup'), $token, $payload, 600);

        if (!($response['success'] ?? false)) {
            throw new PinrollException((string) ($response['error'] ?? 'PinGate setup failed.'));
        }

        $data = $response['data'] ?? [];
        if (!is_array($data)) {
            throw new PinrollException('PinGate setup returned invalid response.');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $db
     * @return array<string, mixed>
     */
    public static function checkDb(string $gateUrlBase, string $token, array $db): array
    {
        $response = self::request('POST', GateUrl::route($gateUrlBase, 'check-db'), $token, ['db' => $db], 60);

        if (!($response['success'] ?? false)) {
            throw new PinrollException((string) ($response['error'] ?? 'PinGate database check failed.'));
        }

        $data = $response['data'] ?? [];
        if (!is_array($data)) {
            throw new PinrollException('PinGate check-db returned invalid response.');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function cleanup(string $gateUrlBase, string $token, array $options = []): array
    {
        $response = self::request('POST', GateUrl::route($gateUrlBase, 'cleanup'), $token, $options);

        if (!($response['success'] ?? false)) {
            throw new PinrollException((string) ($response['error'] ?? 'PinGate cleanup failed.'));
        }

        $data = $response['data'] ?? [];
        if (!is_array($data)) {
            throw new PinrollException('PinGate cleanup returned invalid response.');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function request(string $method, string $url, string $token, array $payload = [], int $timeout = 180): array
    {
        $transport = self::transport($method, $url, $token, $payload, $timeout);

        if (!$transport['reachable']) {
            PinGateRequestLog::write($method, $url, [
                'ok' => false,
                'transport_error' => $transport['error'],
                'status' => 0,
            ]);

            throw new PinrollException(self::transportErrorMessage($url, $transport));
        }

        $body = $transport['body'];
        $status = $transport['status'];

        PinGateRequestLog::write($method, $url, [
            'ok' => true,
            'status' => $status,
            'body_excerpt' => substr(trim($body), 0, 240),
        ]);

        $trimmed = trim($body);
        if ($trimmed === '' || $trimmed[0] !== '{') {
            $probe = PinGateProbe::validateStatusResponse($status, $body, '');

            throw new PinrollException($probe['message']);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new PinrollException('PinGate returned invalid JSON.');
        }

        if (($decoded['success'] ?? null) === false) {
            $error = (string) ($decoded['error'] ?? 'PinGate error');
            if (str_contains(strtolower($error), 'invalid token') || str_contains(strtolower($error), 'unauthorized')) {
                $error .= "\n"
                    . 'Host auth uses storage/pinroll/tokens/{label}.php (hash files).' . "\n"
                    . 'Fix: php pinoox pinroll:token {label} --push' . "\n"
                    . 'Or with FTP deploy: Ensure PinGate uploads pingate.php + your token file automatically.';
            }
            $decoded['error'] = $error;
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{reachable: bool, status: int, body: string, error: ?string}
     */
    private static function transport(string $method, string $url, string $token, array $payload, int $timeout): array
    {
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $content = $method === 'POST' ? json_encode($payload, JSON_THROW_ON_ERROR) : '';

        return PinGateTransport::request($method, $url, $headers, $content, $timeout);
    }

    /**
     * @param array{reachable: bool, status: int, body: string, error: ?string} $transport
     */
    private static function transportErrorMessage(string $url, array $transport): string
    {
        $error = trim((string) ($transport['error'] ?? 'Connection failed'));
        $lines = [
            'PinGate request failed: ' . $url,
            'Transport: ' . $error,
        ];

        $lower = strtolower($error);
        if (str_contains($lower, 'ssl') || str_contains($lower, 'certificate') || str_contains($lower, 'operation failed')) {
            $ca = PinGateTransport::resolveCaFile();
            $lines[] = $ca === null
                ? 'Hint: PHP TLS CA bundle is missing or invalid (openssl.cafile / curl.cainfo). Set them to a real cacert.pem.'
                : 'Hint: Retry after Pinroll uses CA file: ' . $ca;
        } elseif (str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
            $lines[] = 'Hint: The host may still be installing — retry: php pinoox pinroll:install {host} {deploy_id}';
        } elseif (str_contains($lower, 'could not resolve host') || str_contains($lower, 'getaddrinfo')) {
            $lines[] = 'Hint: DNS lookup failed — check gate.site / gate URL in .pinoox/pinroll.config.php.';
        }

        $logPath = PinGateRequestLog::path();
        if ($logPath !== null) {
            $lines[] = 'Log: ' . $logPath;
        }

        return implode("\n", $lines);
    }
}
