<?php

namespace Pinoox\Pinroll\Target;

use Pinoox\Pinroll\Exception\PinrollException;

final class PinGateClient
{
    /**
     * @return array<string, mixed>
     */
    public static function apply(string $gateUrlBase, string $token, string $deployId): array
    {
        $url = rtrim($gateUrlBase, '/') . '/apply';
        $response = self::request('POST', $url, $token, ['deploy_id' => $deployId]);

        if (!($response['success'] ?? false)) {
            throw new PinrollException((string) ($response['error'] ?? 'PinGate apply failed.'));
        }

        $data = $response['data'] ?? [];
        if (!is_array($data)) {
            throw new PinrollException('PinGate apply returned invalid response.');
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function status(string $gateUrlBase, string $token): array
    {
        $url = rtrim($gateUrlBase, '/') . '/status';

        return self::request('GET', $url, $token);
    }

    /**
     * Re-apply a previous release on the host (Pinx force install).
     *
     * @return array<string, mixed>
     */
    public static function rollback(string $gateUrlBase, string $token, string $deployId = ''): array
    {
        $url = rtrim($gateUrlBase, '/') . '/rollback';
        $payload = $deployId !== '' ? ['deploy_id' => $deployId] : [];
        $response = self::request('POST', $url, $token, $payload);

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
        $url = rtrim($gateUrlBase, '/') . '/history';
        $response = self::request('GET', $url, $token);
        $data = $response['data'] ?? $response;

        return is_array($data) ? $data : [];
    }

    /**
     * @return array{releases?: list<array{id: string, path: string, size: int, mtime: int}>}
     */
    public static function incoming(string $gateUrlBase, string $token): array
    {
        $url = rtrim($gateUrlBase, '/') . '/incoming';
        $response = self::request('GET', $url, $token);

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
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function cleanup(string $gateUrlBase, string $token, array $options = []): array
    {
        $url = rtrim($gateUrlBase, '/') . '/cleanup';
        $response = self::request('POST', $url, $token, $options);

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
    private static function request(string $method, string $url, string $token, array $payload = []): array
    {
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $method === 'POST' ? json_encode($payload, JSON_THROW_ON_ERROR) : '',
                'timeout' => 180,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new PinrollException('PinGate request failed: ' . $url);
        }

        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches) === 1) {
            $status = (int) $matches[1];
        }

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
                    . 'Token in .env does not match gate/pingate.php on the host.' . "\n"
                    . 'Fix: php pinoox pinroll:gate {target}  (reuses .env token, FTP upload)' . "\n"
                    . 'Or rotate: php pinoox pinroll:gate {target} --rotate';
            }
            $decoded['error'] = $error;
        }

        return $decoded;
    }
}
