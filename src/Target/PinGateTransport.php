<?php

namespace Pinoox\Pinroll\Target;

final class PinGateTransport
{
    /**
     * @param list<string> $headers
     * @return array{reachable: bool, status: int, body: string, error: ?string}
     */
    public static function request(string $method, string $url, array $headers, string $content = '', int $timeout = 30): array
    {
        $timeout = $timeout > 0 ? $timeout : 30;
        $connectTimeout = min(15, $timeout);
        $caFile = self::resolveCaFile();

        if (function_exists('curl_init')) {
            $curl = self::viaCurl($method, $url, $headers, $content, $timeout, $connectTimeout, $caFile);
            if ($curl['reachable']) {
                return $curl;
            }

            $stream = self::viaStream($method, $url, $headers, $content, $timeout, $caFile);
            if ($stream['reachable']) {
                return $stream;
            }

            return [
                'reachable' => false,
                'status' => 0,
                'body' => '',
                'error' => self::mergeErrors($curl['error'] ?? null, $stream['error'] ?? null),
            ];
        }

        return self::viaStream($method, $url, $headers, $content, $timeout, $caFile);
    }

    public static function resolveCaFile(): ?string
    {
        foreach (self::caCandidates() as $path) {
            if (self::isUsableCaFile($path)) {
                return $path;
            }
        }

        return null;
    }

    public static function isUsableCaFile(string $path): bool
    {
        $path = trim($path);
        if ($path === '' || preg_match('/MAMP_BASEDIR/i', $path) === 1) {
            return false;
        }

        return is_file($path) && is_readable($path) && filesize($path) > 1024;
    }

    /**
     * @return list<string>
     */
    public static function caCandidates(): array
    {
        $candidates = [
            (string) getenv('SSL_CERT_FILE'),
            (string) ini_get('curl.cainfo'),
            (string) ini_get('openssl.cafile'),
            'C:\\MAMP\\bin\\apache\\bin\\cacert.pem',
            dirname(PHP_BINARY) . '/extras/ssl/cacert.pem',
            dirname(PHP_BINARY) . '/ssl/cacert.pem',
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
            '/usr/local/etc/openssl/cert.pem',
            '/opt/homebrew/etc/openssl@3/cert.pem',
        ];

        $out = [];
        foreach ($candidates as $path) {
            $path = str_replace(["\0"], '', $path);
            if ($path !== '') {
                $out[] = $path;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $headers
     * @return array{reachable: bool, status: int, body: string, error: ?string}
     */
    private static function viaCurl(
        string $method,
        string $url,
        array $headers,
        string $content,
        int $timeout,
        int $connectTimeout,
        ?string $caFile,
    ): array {
        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'reachable' => false,
                'status' => 0,
                'body' => '',
                'error' => 'Unable to initialize cURL.',
            ];
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_FOLLOWLOCATION => false,
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POSTFIELDS] = $content;
        }

        if ($caFile !== null) {
            $options[CURLOPT_CAINFO] = $caFile;
        }

        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return [
                'reachable' => false,
                'status' => 0,
                'body' => '',
                'error' => $curlError !== '' ? $curlError : 'cURL request failed',
            ];
        }

        return [
            'reachable' => true,
            'status' => $status,
            'body' => (string) $body,
            'error' => null,
        ];
    }

    /**
     * @param list<string> $headers
     * @return array{reachable: bool, status: int, body: string, error: ?string}
     */
    private static function viaStream(
        string $method,
        string $url,
        array $headers,
        string $content,
        int $timeout,
        ?string $caFile,
    ): array {
        $ssl = [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ];
        if ($caFile !== null) {
            $ssl['cafile'] = $caFile;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $method === 'POST' ? $content : '',
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => $ssl,
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            $error = error_get_last();

            return [
                'reachable' => false,
                'status' => 0,
                'body' => '',
                'error' => is_array($error) ? (string) ($error['message'] ?? 'Connection failed') : 'Connection failed',
            ];
        }

        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches) === 1) {
            $status = (int) $matches[1];
        }

        return [
            'reachable' => true,
            'status' => $status,
            'body' => $body,
            'error' => null,
        ];
    }

    private static function mergeErrors(?string $first, ?string $second): string
    {
        $parts = array_values(array_unique(array_filter([
            is_string($first) ? trim($first) : '',
            is_string($second) ? trim($second) : '',
        ])));

        return $parts !== [] ? implode(' | ', $parts) : 'Connection failed';
    }
}
