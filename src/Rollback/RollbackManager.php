<?php

namespace Pinoox\Pinroll\Rollback;

use Pinoox\Pinroll\Bridge\PincoreBridge;
use Pinoox\Pinroll\Release\ReleaseManifest;
use Pinoox\Pinroll\Rollout\RolloutHistory;
use Pinoox\Pinroll\Rollout\RolloutSession;
use Pinoox\Pinroll\Rollout\SnapshotStore;
use Pinoox\Pinroll\Strategy\StrategyDetector;
use Pinoox\Pinroll\Support\Config;

final class RollbackManager
{
    public function __construct(
        private readonly Config $config,
        private readonly RolloutHistory $history,
        private readonly SnapshotStore $snapshots,
        private readonly StrategyDetector $strategies,
        private readonly ?PincoreBridge $bridge = null,
    ) {
    }

    public function rollback(?ReleaseManifest $manifest, RolloutSession $session, ?string $previousDeployId = null): void
    {
        $session->addStep('rollback', 'running', 'Starting rollback');

        $strategy = $this->strategies->detect();
        if ($manifest !== null) {
            $strategy->rollback($manifest, $session, [
                'previous_release' => $previousDeployId,
            ]);
        } else {
            $last = $this->history->lastSuccessful();
            if ($last !== null) {
                $this->snapshots->restore((string) ($last['deploy_id'] ?? ''));
            }
        }

        if ($this->bridge !== null) {
            $this->bridge->rollbackMigrations($session);
        }

        $session->markRolledBack();
        $this->history->append(array_merge($session->toArray(), ['action' => 'rollback']));
    }
}
