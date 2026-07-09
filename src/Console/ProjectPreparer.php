<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\ProjectPaths;

final class ProjectPreparer
{
    public function __construct(
        private readonly ?string $projectRoot = null,
        private readonly bool $force = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function prepare(string $target = 'production', bool $zip = false): array
    {
        $root = $this->projectRoot ?? (defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd());
        $paths = new NativePathResolver((string) $root);

        $configCreated = [];
        if (!ProjectPaths::isInitialized($paths) || $this->force) {
            $configCreated = (new ProjectInitializer((string) $root, $this->force))->init();
        }

        return array_merge(
            ['config_created' => $configCreated, 'target' => $target],
            (new DeployRunner((string) $root))->initGate($target, $zip),
        );
    }

    /**
     * @return array{url: string, token: string}
     */
    public static function envKeysForTarget(string $target): array
    {
        $slug = strtoupper(preg_replace('/[^a-zA-Z0-9]+/', '_', $target) ?: 'TARGET');

        return [
            'url' => 'PINROLL_' . $slug . '_URL',
            'token' => 'PINROLL_' . $slug . '_TOKEN',
        ];
    }
}
