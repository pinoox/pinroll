<?php

namespace Pinoox\Pinroll\Rollout;

use Pinoox\Pinroll\Support\Config;
use Pinoox\Pinroll\Support\TokenGenerator;

final class RolloutSession
{
    private const STATUS_RUNNING = 'running';
    private const STATUS_COMMITTED = 'committed';
    private const STATUS_ROLLED_BACK = 'rolled_back';
    private const STATUS_FAILED = 'failed';

    public function __construct(
        private readonly Config $config,
        private readonly string $id,
        /** @var array<string, mixed> */
        private array $data,
    ) {
    }

    public static function create(Config $config, string $target, string $bundle, string $transport): self
    {
        $id = TokenGenerator::deployId();
        $data = [
            'id' => $id,
            'target' => $target,
            'bundle' => $bundle,
            'transport' => $transport,
            'status' => self::STATUS_RUNNING,
            'progress' => 0,
            'steps' => [],
            'error' => null,
            'started_at' => date('c'),
            'updated_at' => time(),
        ];

        $session = new self($config, $id, $data);
        $session->persist();

        return $session;
    }

    public static function load(Config $config, string $id): ?self
    {
        $path = self::pathFor($config, $id);
        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return null;
        }

        return new self($config, $id, $data);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function status(): string
    {
        return (string) ($this->data['status'] ?? self::STATUS_RUNNING);
    }

    public function addStep(string $step, string $status, string $message): void
    {
        $steps = is_array($this->data['steps'] ?? null) ? $this->data['steps'] : [];
        $steps[] = compact('step', 'status', 'message');
        $this->data['steps'] = $steps;
        $this->data['progress'] = min(99, count($steps) * 8);
        $this->data['updated_at'] = time();
        $this->persist();
    }

    public function markCommitted(): void
    {
        $this->data['status'] = self::STATUS_COMMITTED;
        $this->data['progress'] = 100;
        $this->data['finished_at'] = date('c');
        $this->persist();
    }

    public function markRolledBack(string $reason = ''): void
    {
        $this->data['status'] = self::STATUS_ROLLED_BACK;
        $this->data['error'] = $reason;
        $this->data['finished_at'] = date('c');
        $this->persist();
    }

    public function markFailed(string $reason): void
    {
        $this->data['status'] = self::STATUS_FAILED;
        $this->data['error'] = $reason;
        $this->data['finished_at'] = date('c');
        $this->persist();
    }

    public function lastInstallError(): string
    {
        $steps = is_array($this->data['steps'] ?? null) ? $this->data['steps'] : [];

        for ($index = count($steps) - 1; $index >= 0; $index--) {
            $step = $steps[$index];
            if (!is_array($step)) {
                continue;
            }

            $name = (string) ($step['step'] ?? '');
            $status = (string) ($step['status'] ?? '');
            $message = (string) ($step['message'] ?? '');

            if (str_starts_with($name, 'install') && in_array($status, ['failed', 'error'], true)) {
                return $message !== '' ? $message : $name;
            }
        }

        return (string) ($this->data['error'] ?? '');
    }

    /**
     * @param array<string, mixed> $patch
     */
    public function patch(array $patch): void
    {
        $this->data = array_replace($this->data, $patch);
        $this->data['updated_at'] = time();
        $this->persist();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    private function persist(): void
    {
        $path = self::pathFor($this->config, $this->id);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private static function pathFor(Config $config, string $id): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $id) ?: 'unknown';

        return $config->storage('sessions/' . $safe . '.json');
    }
}
