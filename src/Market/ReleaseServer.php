<?php

namespace Pinoox\Pinroll\Market;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Release\ReleaseManifest;

/**
 * Pull-based release channel (Phase 4).
 */
final class ReleaseServer
{
    public function __construct(private readonly string $baseUrl)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function publish(ReleaseManifest $manifest, string $channel = 'stable'): array
    {
        $payload = [
            'channel' => $channel,
            'manifest' => $manifest->toArray(),
            'published_at' => date('c'),
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode($payload, JSON_THROW_ON_ERROR),
                'timeout' => 30,
            ],
        ]);

        $response = @file_get_contents(rtrim($this->baseUrl, '/') . '/publish', false, $context);
        if ($response === false) {
            throw new PinrollException('Failed to publish release.');
        }

        $decoded = json_decode($response, true);

        return is_array($decoded) ? $decoded : [];
    }
}

final class ManifestPoller
{
    public function __construct(private readonly string $baseUrl)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function poll(string $channel = 'stable', ?string $currentVersion = null): ?array
    {
        $url = rtrim($this->baseUrl, '/') . '/manifest?channel=' . urlencode($channel);
        if ($currentVersion !== null) {
            $url .= '&current=' . urlencode($currentVersion);
        }

        $response = @file_get_contents($url);
        if ($response === false) {
            return null;
        }

        $decoded = json_decode($response, true);

        return is_array($decoded) ? $decoded : null;
    }
}
