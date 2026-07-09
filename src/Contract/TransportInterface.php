<?php

namespace Pinoox\Pinroll\Contract;

use Pinoox\Pinroll\Release\ReleaseManifest;
use Pinoox\Pinroll\Rollout\RolloutSession;

interface TransportInterface
{
    public function name(): string;

    /**
     * @param array<string, mixed> $target
     */
    public function send(string $archivePath, ReleaseManifest $manifest, array $target, RolloutSession $session): void;
}
