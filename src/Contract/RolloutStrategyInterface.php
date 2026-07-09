<?php

namespace Pinoox\Pinroll\Contract;

use Pinoox\Pinroll\Release\ReleaseManifest;
use Pinoox\Pinroll\Rollout\RolloutSession;

interface RolloutStrategyInterface
{
    public function name(): string;

    /**
     * @param array<string, mixed> $context
     */
    public function snapshot(ReleaseManifest $manifest, RolloutSession $session, array $context): void;

    /**
     * @param array<string, mixed> $context
     */
    public function stage(ReleaseManifest $manifest, string $archivePath, RolloutSession $session, array $context): string;

    /**
     * @param array<string, mixed> $context
     */
    public function commit(ReleaseManifest $manifest, RolloutSession $session, array $context): void;

    /**
     * @param array<string, mixed> $context
     */
    public function rollback(ReleaseManifest $manifest, RolloutSession $session, array $context): void;
}
