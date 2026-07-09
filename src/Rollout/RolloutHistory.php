<?php

namespace Pinoox\Pinroll\Rollout;

use Pinoox\Pinroll\Support\Config;

final class RolloutHistory
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function append(array $entry): void
    {
        $path = $this->path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $entry['recorded_at'] = date('c');
        file_put_contents($path, json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(int $limit = 50): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return [];
        }

        $lines = array_filter(array_map('trim', file($path, FILE_IGNORE_NEW_LINES) ?: []));
        $rows = [];

        foreach (array_slice(array_reverse($lines), 0, $limit) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    public function lastSuccessful(): ?array
    {
        foreach ($this->all(100) as $entry) {
            if (($entry['status'] ?? '') === 'committed') {
                return $entry;
            }
        }

        return null;
    }

    private function path(): string
    {
        return $this->config->storage((string) $this->config->get('history_file', 'pinroll/history.jsonl'));
    }
}
