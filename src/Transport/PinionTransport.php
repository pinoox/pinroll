<?php

namespace Pinoox\Pinroll\Transport;

use Pinoox\Pinroll\Console\GateUrl;
use Pinoox\Pinroll\Contract\TransportInterface;
use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Release\ReleaseManifest;
use Pinoox\Pinroll\Rollout\RolloutSession;
use Pinoox\Pinroll\Support\Config;
use Pinoox\Pinroll\Support\PushProgress;
use Pinoox\Pinroll\Target\PinGateProbe;
use Pinoox\Pinroll\Target\PinGateRequestLog;
use Pinoox\Pinroll\Target\PinGateTransport;

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
        $baseUrl = (string) ($target['gate_url'] ?? '');
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

        PushProgress::arrow($filename . ' (' . $this->formatBytes($size) . ') via Pinion');

        $init = $this->json('POST', GateUrl::route($baseUrl, 'push/init'), $token, [
            'filename' => $filename,
            'size' => $size,
            'chunk_size' => $chunkSize,
            'meta' => [
                'deploy_id' => $manifest->deployId(),
                'checksum' => $manifest->checksum(),
            ],
        ]);

        $uploadId = $this->uploadIdFromInit($init);
        if ($uploadId === '') {
            throw new PinrollException('Pinion init failed — no upload id returned.');
        }

        $handle = fopen($archivePath, 'rb');
        if ($handle === false) {
            throw new PinrollException('Cannot read archive.');
        }

        $index = 0;
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, $chunkSize);
                if ($chunk === false || $chunk === '') {
                    break;
                }

                $this->uploadChunk($baseUrl, $uploadId, $index, $chunk, $token);
                $index++;
                $uploaded = min($size, $index * $chunkSize);
                PushProgress::progress($uploaded, max(1, $size), $filename);
                $session->addStep('transport', 'running', 'Uploaded chunk ' . $index);
            }
        } finally {
            fclose($handle);
        }

        $this->json('POST', GateUrl::route($baseUrl, 'push/complete'), $token, [
            'upload_id' => $uploadId,
            'file_hash' => hash_file('sha256', $archivePath),
        ]);

        $session->addStep('transport', 'ok', 'Archive delivered via Pinion');
    }

    /**
     * @param array<string, mixed> $init
     */
    private function uploadIdFromInit(array $init): string
    {
        $queue = [$init];
        while ($queue !== []) {
            $node = array_shift($queue);
            if (!is_array($node)) {
                continue;
            }

            foreach (['id', 'upload_id', 'uploadId'] as $key) {
                $value = trim((string) ($node[$key] ?? ''));
                if ($value !== '' && preg_match('/^[a-zA-Z0-9._-]{8,}$/', $value) === 1) {
                    return $value;
                }
            }

            foreach (['data', 'session'] as $nest) {
                if (isset($node[$nest]) && is_array($node[$nest])) {
                    $queue[] = $node[$nest];
                }
            }
        }

        return '';
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

        $headers = [
            'Accept: application/json',
            'Content-Type: multipart/form-data; boundary=' . $boundary,
        ];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
            $headers[] = 'X-Pinroll-Deploy-Id: ' . $uploadId;
        }

        $url = GateUrl::route($baseUrl, 'push/upload');
        $transport = PinGateTransport::request('POST', $url, $headers, $body, 120);
        $decoded = $this->decode($url, $transport);
        if (($decoded['success'] ?? false) === false) {
            $error = $decoded['error'] ?? 'chunk failed';
            $message = is_array($error) ? (string) ($error['message'] ?? json_encode($error)) : (string) $error;

            throw new PinrollException('Chunk upload failed at index ' . $index . ': ' . $message);
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
                'Pinion request failed: ' . $url
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
            throw new PinrollException('Pinion returned invalid JSON.');
        }

        if (($decoded['success'] ?? null) === false) {
            $error = $decoded['error'] ?? 'Pinion error';
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
