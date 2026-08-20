<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Support\PushProgress;
use Pinoox\Pinroll\Target\PinGateProbe;
use Pinoox\Pinroll\Target\PinGateRequestLog;
use Pinoox\Pinroll\Target\PinGateTransport;

/**
 * Chunked HTTP upload of platform.zip / vendor.zip through PinGate (no FTP data channel).
 */
final class GateZipUploader
{
    private const CHUNK_SIZE = 256 * 1024;

    private const CHUNK_RETRIES = 8;

    public function upload(string $gateUrl, string $token, string $localZip, string $remoteName): void
    {
        if (!is_file($localZip)) {
            throw new PinrollException('Zip not found: ' . $localZip);
        }

        $remoteName = basename($remoteName);
        if (!in_array($remoteName, ['platform.zip', 'vendor.zip'], true)) {
            throw new PinrollException('HTTP zip upload only supports platform.zip or vendor.zip.');
        }

        $size = (int) filesize($localZip);
        PushProgress::arrow($remoteName . ' (' . $this->formatBytes($size) . ') via PinGate HTTP');

        $init = $this->json('POST', GateUrl::route($gateUrl, 'put/init'), $token, [
            'filename' => $remoteName,
            'size' => $size,
            'chunk_size' => self::CHUNK_SIZE,
        ]);

        $uploadId = trim((string) (($init['data']['id'] ?? $init['data']['upload_id'] ?? $init['id'] ?? '')));
        if ($uploadId === '' || !str_starts_with($uploadId, 'pbl_')) {
            throw new PinrollException('PinGate put/init did not return an upload id. Re-upload pingate.php (php pinoox pinroll:gate).');
        }

        $handle = fopen($localZip, 'rb');
        if ($handle === false) {
            throw new PinrollException('Cannot read zip: ' . $localZip);
        }

        $index = 0;
        $received = (int) ($init['data']['received'] ?? $init['received'] ?? 0);
        if ($received > 0 && $received < $size) {
            fseek($handle, $received);
            $index = (int) floor($received / self::CHUNK_SIZE);
            PushProgress::arrow('Resuming HTTP upload at ' . $this->formatBytes($received));
        }
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, self::CHUNK_SIZE);
                if ($chunk === false || $chunk === '') {
                    break;
                }

                $this->uploadChunkWithRetry($gateUrl, $uploadId, $index, $chunk, $token);
                $index++;
                $uploaded = min($size, $index * self::CHUNK_SIZE);
                PushProgress::progress($uploaded, max(1, $size), $remoteName);
            }
        } finally {
            fclose($handle);
        }

        $this->json('POST', GateUrl::route($gateUrl, 'put/complete'), $token, [
            'upload_id' => $uploadId,
            'file_hash' => hash_file('sha256', $localZip),
        ]);
        PushProgress::arrow('HTTP upload done');
    }

    private function uploadChunkWithRetry(string $gateUrl, string $uploadId, int $index, string $chunk, string $token): void
    {
        $last = null;
        for ($attempt = 1; $attempt <= self::CHUNK_RETRIES; $attempt++) {
            try {
                $this->uploadChunk($gateUrl, $uploadId, $index, $chunk, $token);

                return;
            } catch (\Throwable $e) {
                $last = $e;
                $message = strtolower($e->getMessage());
                $transient = str_contains($message, 'reset')
                    || str_contains($message, 'timed out')
                    || str_contains($message, 'timeout')
                    || str_contains($message, 'failed to open stream');
                if (!$transient || $attempt === self::CHUNK_RETRIES) {
                    throw $e;
                }
                PushProgress::warn('Chunk ' . $index . ' retry ' . $attempt . '/' . self::CHUNK_RETRIES . ': ' . $e->getMessage());
                usleep(250000 * $attempt);
            }
        }

        throw $last ?? new PinrollException('HTTP chunk failed at index ' . $index);
    }

    private function uploadChunk(string $gateUrl, string $uploadId, int $index, string $chunk, string $token): void
    {
        $boundary = 'pinroll' . bin2hex(random_bytes(8));
        $body = "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"upload_id\"\r\n\r\n{$uploadId}\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"index\"\r\n\r\n{$index}\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= 'Content-Disposition: form-data; name="chunk"; filename="chunk.bin"' . "\r\n";
        $body .= "Content-Type: application/octet-stream\r\n\r\n";
        $body .= $chunk . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $headers = [
            'Accept: application/json',
            'Content-Type: multipart/form-data; boundary=' . $boundary,
        ];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $url = GateUrl::route($gateUrl, 'put/upload');
        $transport = PinGateTransport::request('POST', $url, $headers, $body, 120);
        $decoded = $this->decode($url, $transport);
        if (($decoded['success'] ?? false) === false) {
            $error = $decoded['error'] ?? 'chunk failed';
            $message = is_array($error) ? (string) ($error['message'] ?? json_encode($error)) : (string) $error;

            throw new PinrollException('HTTP chunk failed at index ' . $index . ': ' . $message);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function json(string $method, string $url, string $token, array $payload): array
    {
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $content = $method === 'POST' ? json_encode($payload, JSON_THROW_ON_ERROR) : '';
        $transport = PinGateTransport::request($method, $url, $headers, $content, 60);

        return $this->decode($url, $transport);
    }

    /**
     * @param array{reachable: bool, status: int, body: string, error: ?string} $transport
     * @return array<string, mixed>
     */
    private function decode(string $url, array $transport): array
    {
        if (!$transport['reachable']) {
            PinGateRequestLog::write('POST', $url, [
                'ok' => false,
                'transport_error' => $transport['error'],
                'status' => 0,
            ]);

            throw new PinrollException(
                'PinGate zip upload failed: ' . $url
                . ($transport['error'] ? "\n" . $transport['error'] : ''),
            );
        }

        PinGateRequestLog::write('POST', $url, [
            'ok' => true,
            'status' => $transport['status'],
            'body_excerpt' => substr(trim($transport['body']), 0, 240),
        ]);

        $trimmed = trim($transport['body']);
        if ($trimmed === '' || $trimmed[0] !== '{') {
            $probe = PinGateProbe::validateStatusResponse($transport['status'], $transport['body'], '');

            throw new PinrollException($probe['message']);
        }

        $decoded = json_decode($transport['body'], true);
        if (!is_array($decoded)) {
            throw new PinrollException('PinGate returned invalid JSON for zip upload.');
        }

        if (($decoded['success'] ?? null) === false) {
            $error = $decoded['error'] ?? 'PinGate error';
            $message = is_array($error) ? (string) ($error['message'] ?? json_encode($error)) : (string) $error;

            throw new PinrollException($message);
        }

        return $decoded;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / (1024 * 1024), 1) . ' MB';
    }
}
