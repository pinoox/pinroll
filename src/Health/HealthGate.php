<?php

namespace Pinoox\Pinroll\Health;

use Pinoox\Pinroll\Bridge\PincoreBridge;
use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Release\ReleaseManifest;
use Pinoox\Pinroll\Rollout\RolloutSession;

final class HealthGate
{
    public function __construct(private readonly ?PincoreBridge $bridge = null)
    {
    }

    /**
     * @return array<string, bool>
     */
    public function check(ReleaseManifest $manifest, RolloutSession $session, ?string $baseUrl = null): array
    {
        $results = [];

        if ($this->bridge !== null) {
            $results['database'] = $this->bridge->checkDatabase();
            $results['storage'] = $this->bridge->checkStorageWritable();
        } else {
            $results['database'] = true;
            $results['storage'] = is_writable(sys_get_temp_dir());
        }

        foreach ($manifest->healthChecks() as $path) {
            $key = 'http:' . $path;
            $results[$key] = $this->checkHttp($baseUrl, $path);
        }

        $session->addStep('health', 'ok', 'Health checks completed');

        foreach ($results as $name => $ok) {
            if (!$ok) {
                throw new PinrollException('Health check failed: ' . $name);
            }
        }

        return $results;
    }

    private function checkHttp(?string $baseUrl, string $path): bool
    {
        if ($baseUrl === null || $baseUrl === '') {
            return true;
        }

        $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
        $context = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $context);

        return $body !== false;
    }
}
