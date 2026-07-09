<?php

namespace Pinoox\Pinroll\Support;

use Pinoox\Pinroll\Contract\PathResolverInterface;

final class Config
{
    /** @var array<string, mixed> */
    private array $resolved;

    /**
     * @param array<string, mixed> $overrides
     */
    public function __construct(
        private readonly PathResolverInterface $paths,
        array $overrides = [],
    ) {
        $defaults = $this->loadDefaults();
        $this->resolved = array_replace_recursive($defaults, $overrides);
        $this->resolved['storage_path'] = rtrim(str_replace('\\', '/', (string) $this->resolved['storage_path']), '/');
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->resolved;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->resolved[$key] ?? $default;
    }

    public function paths(): PathResolverInterface
    {
        return $this->paths;
    }

    public function storage(string $relative = ''): string
    {
        $base = $this->paths->storage();

        if (is_dir($base)) {
            $path = $relative === '' ? $base : $base . '/' . ltrim($relative, '/');

            return $path;
        }

        $fallback = $this->get('storage_path', sys_get_temp_dir() . '/pinroll');

        return $relative === '' ? $fallback : $fallback . '/' . ltrim($relative, '/');
    }

    /**
     * @return array<string, mixed>
     */
    private function loadDefaults(): array
    {
        $file = dirname(__DIR__, 2) . '/config/pinroll.php';

        return is_file($file) ? (require $file) : [];
    }
}
