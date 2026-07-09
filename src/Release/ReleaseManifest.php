<?php

namespace Pinoox\Pinroll\Release;

final class ReleaseManifest
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data)
    {
    }

  /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public static function fromJsonFile(string $path): self
    {
        $raw = json_decode((string) file_get_contents($path), true);

        return new self(is_array($raw) ? $raw : []);
    }

    public function id(): string
    {
        return (string) ($this->data['id'] ?? $this->data['package'] ?? '');
    }

    public function version(): string
    {
        return (string) ($this->data['version'] ?? $this->data['version-name'] ?? '');
    }

    public function scope(): string
    {
        return (string) ($this->data['deploy']['scope'] ?? $this->data['scope'] ?? 'app');
    }

    /**
     * @return array<string, mixed>
     */
    public function deploy(): array
    {
        $deploy = $this->data['deploy'] ?? [];

        return is_array($deploy) ? $deploy : [];
    }

    /**
     * @return list<string>
     */
    public function healthChecks(): array
    {
        $checks = $this->deploy()['health_checks'] ?? [];

        return is_array($checks) ? array_map('strval', $checks) : [];
    }

    public function checksum(): string
    {
        return (string) ($this->data['files_checksum'] ?? $this->data['checksum'] ?? '');
    }

    public function signature(): string
    {
        return (string) ($this->data['signature'] ?? '');
    }

    public function archivePath(): string
    {
        return (string) ($this->data['archive_path'] ?? '');
    }

    public function deployId(): string
    {
        return (string) ($this->data['deploy_id'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function write(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
