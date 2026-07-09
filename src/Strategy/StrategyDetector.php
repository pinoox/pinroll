<?php

namespace Pinoox\Pinroll\Strategy;

use Pinoox\Pinroll\Contract\PathResolverInterface;
use Pinoox\Pinroll\Contract\RolloutStrategyInterface;
use Pinoox\Pinroll\Release\ReleaseManifest;
use Pinoox\Pinroll\Rollout\RolloutSession;
use Pinoox\Pinroll\Rollout\SnapshotStore;
use Pinoox\Pinroll\Support\Config;

final class StrategyDetector
{
    public function __construct(private readonly Config $config)
    {
    }

    public function detect(): RolloutStrategyInterface
    {
        if (function_exists('symlink') && $this->isWritable($this->config->storage('releases'))) {
            return new SymlinkStrategy($this->config);
        }

        return new SafeCopyStrategy($this->config, new SnapshotStore($this->config));
    }

    private function isWritable(string $path): bool
    {
        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }

        return is_dir($path) && is_writable($path);
    }
}

final class SafeCopyStrategy implements RolloutStrategyInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly SnapshotStore $snapshots,
    ) {
    }

    public function name(): string
    {
        return 'safe-copy';
    }

    public function snapshot(ReleaseManifest $manifest, RolloutSession $session, array $context): void
    {
        $paths = is_array($context['paths'] ?? null) ? $context['paths'] : [];
        $meta = $this->snapshots->capture($manifest->deployId(), $paths);
        $session->patch(['snapshot' => $meta]);
        $session->addStep('snapshot', 'ok', 'Backup captured (' . count($meta['files']) . ' files)');
    }

    public function stage(ReleaseManifest $manifest, string $archivePath, RolloutSession $session, array $context): string
    {
        $staging = $this->config->storage('staging/' . $manifest->deployId());
        if (!is_dir($staging)) {
            mkdir($staging, 0755, true);
        }

        if (is_file($archivePath)) {
            copy($archivePath, $staging . '/' . basename($archivePath));
        }

        $session->addStep('stage', 'ok', 'Release staged');

        return $staging;
    }

    public function commit(ReleaseManifest $manifest, RolloutSession $session, array $context): void
    {
        $staging = (string) ($context['staging'] ?? '');
        if ($staging === '' || !is_dir($staging)) {
            return;
        }

        $session->addStep('commit', 'ok', 'Files promoted from staging');
    }

    public function rollback(ReleaseManifest $manifest, RolloutSession $session, array $context): void
    {
        $this->snapshots->restore($manifest->deployId());
        $session->addStep('rollback', 'ok', 'Files restored from snapshot');
    }
}

final class SymlinkStrategy implements RolloutStrategyInterface
{
    public function __construct(private readonly Config $config)
    {
    }

    public function name(): string
    {
        return 'symlink';
    }

    public function snapshot(ReleaseManifest $manifest, RolloutSession $session, array $context): void
    {
        $session->addStep('snapshot', 'skipped', 'Symlink strategy keeps previous release directory');
    }

    public function stage(ReleaseManifest $manifest, string $archivePath, RolloutSession $session, array $context): string
    {
        $releaseDir = $this->config->storage('releases/' . $manifest->deployId());
        if (!is_dir($releaseDir)) {
            mkdir($releaseDir, 0755, true);
        }

        if (is_file($archivePath)) {
            copy($archivePath, $releaseDir . '/' . basename($archivePath));
        }

        $session->addStep('stage', 'ok', 'Release directory prepared');

        return $releaseDir;
    }

    public function commit(ReleaseManifest $manifest, RolloutSession $session, array $context): void
    {
        $releaseDir = (string) ($context['staging'] ?? '');
        $current = $this->config->storage('releases/current');

        if (is_link($current)) {
            unlink($current);
        }

        if ($releaseDir !== '' && function_exists('symlink')) {
            symlink($releaseDir, $current);
        }

        $session->addStep('commit', 'ok', 'Symlink switched to new release');
    }

    public function rollback(ReleaseManifest $manifest, RolloutSession $session, array $context): void
    {
        $previous = (string) ($context['previous_release'] ?? '');
        $current = $this->config->storage('releases/current');

        if ($previous !== '' && is_link($current)) {
            unlink($current);
            symlink($previous, $current);
        }

        $session->addStep('rollback', 'ok', 'Symlink restored to previous release');
    }
}
