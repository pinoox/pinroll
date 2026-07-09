<?php

namespace Pinoox\Pinroll\Transport;

use Pinoox\Pinroll\Contract\TransportInterface;
use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Release\ReleaseManifest;
use Pinoox\Pinroll\Rollout\RolloutSession;
use Pinoox\Pinroll\Support\Config;
use Pinoox\Pinroll\Support\PushProgress;

final class PinionTransport implements TransportInterface
{
    public function __construct(private readonly Config $config)
    {
    }

    public function name(): string
    {
        return 'pinion';
    }

    public function send(string $archivePath, ReleaseManifest $manifest, array $target, RolloutSession $session): void
    {
        $baseUrl = rtrim((string) ($target['gate_url'] ?? ''), '/');
        if ($baseUrl === '') {
            throw new PinrollException('Target gate_url is required for pinion transport.');
        }

        if (!is_file($archivePath)) {
            throw new PinrollException('Archive not found: ' . $archivePath);
        }

        $token = (string) ($target['token'] ?? '');
        $size = (int) filesize($archivePath);
        $chunkSize = (int) $this->config->get('chunk_size', 5 * 1024 * 1024);
        $filename = basename($archivePath);

        PushProgress::log('Uploading ' . $filename . ' (' . $this->formatBytes($size) . ') via Pinion…');

        $init = $this->post($baseUrl . '/push/init', [
            'filename' => $filename,
            'size' => $size,
            'destination' => 'pinroll/incoming',
            'chunk_size' => $chunkSize,
            'meta' => [
                'deploy_id' => $manifest->deployId(),
                'checksum' => $manifest->checksum(),
            ],
        ], $token);

        $uploadId = (string) ($init['data']['id'] ?? $init['data']['upload_id'] ?? '');
        if ($uploadId === '') {
            throw new PinrollException('Pinion init failed.');
        }

        $handle = fopen($archivePath, 'rb');
        if ($handle === false) {
            throw new PinrollException('Cannot read archive.');
        }

        $index = 0;
        while (!feof($handle)) {
            $chunk = fread($handle, $chunkSize);
            if ($chunk === false || $chunk === '') {
                break;
            }

            $this->uploadChunk($baseUrl, $uploadId, $index, $chunk, $token);
            $index++;
            $uploaded = min($size, $index * $chunkSize);
            $percent = $size > 0 ? (int) round(($uploaded / $size) * 100) : 100;
            PushProgress::log('Uploaded chunk ' . $index . ' (' . $percent . '%)');
            $session->addStep('transport', 'running', "Uploaded chunk {$index}");
        }

        fclose($handle);

        $this->post($baseUrl . '/push/complete', [
            'upload_id' => $uploadId,
            'file_hash' => 'sha256:' . hash_file('sha256', $archivePath),
        ], $token);

        $session->addStep('transport', 'ok', 'Archive delivered via Pinion');
    }

    private function uploadChunk(string $baseUrl, string $uploadId, int $index, string $chunk, string $token): void
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

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: multipart/form-data; boundary=' . $boundary,
                    'X-Pinroll-Deploy-Id: ' . $uploadId,
                ]),
                'content' => $body,
                'timeout' => 120,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($baseUrl . '/push/upload', false, $context);
        if ($response === false) {
            throw new PinrollException("Chunk upload failed at index {$index}");
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function post(string $url, array $payload, string $token): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $token,
                ]),
                'content' => $body,
                'timeout' => 60,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new PinrollException('HTTP request failed: ' . $url);
        }

        $decoded = json_decode($response, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / (1024 * 1024), 1) . ' MB';
    }
}
