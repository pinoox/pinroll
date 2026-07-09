<?php

namespace Pinoox\Pinroll\Rollout;

use Pinoox\Pinroll\Bridge\PincoreBridge;
use Pinoox\Pinroll\Contract\PathResolverInterface;
use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Health\HealthGate;
use Pinoox\Pinroll\Release\ReleaseManifest;
use Pinoox\Pinroll\Release\ReleaseVerifier;
use Pinoox\Pinroll\Rollback\RollbackManager;
use Pinoox\Pinroll\Strategy\StrategyDetector;
use Pinoox\Pinroll\Support\Config;

final class RolloutEngine
{
    private readonly RolloutLock $lock;
    private readonly RolloutHistory $history;
    private readonly SnapshotStore $snapshots;
    private readonly StrategyDetector $strategies;
    private readonly ReleaseVerifier $verifier;
    private readonly HealthGate $health;
    private readonly RollbackManager $rollback;
    private readonly ?PincoreBridge $bridge;

    public function __construct(
        private readonly Config $config,
        private readonly PathResolverInterface $paths,
    ) {
        $this->lock = new RolloutLock($config);
        $this->history = new RolloutHistory($config);
        $this->snapshots = new SnapshotStore($config);
        $this->strategies = new StrategyDetector($config);
        $this->verifier = new ReleaseVerifier();
        $this->bridge = class_exists(PincoreBridge::class) ? new PincoreBridge() : null;
        $this->health = new HealthGate($this->bridge);
        $this->rollback = new RollbackManager($config, $this->history, $this->snapshots, $this->strategies, $this->bridge);
    }

  /**
     * @param array<string, mixed> $context
     */
    public function apply(ReleaseManifest $manifest, RolloutSession $session, array $context = []): void
    {
        $strategy = $this->strategies->detect();

        try {
            $this->lock->acquire($manifest->deployId());
            $session->addStep('lock', 'ok', 'Rollout lock acquired');

            $publicKey = isset($context['public_key']) ? (string) $context['public_key'] : null;
            $this->verifier->verify($manifest, $publicKey);
            $session->addStep('verify', 'ok', 'Release verified');

            $paths = is_array($context['snapshot_paths'] ?? null) ? $context['snapshot_paths'] : [];
            $strategy->snapshot($manifest, $session, ['paths' => $paths]);

            $staging = $strategy->stage($manifest, $manifest->archivePath(), $session, $context);

            $skipInstall = (bool) ($manifest->deploy()['skip_install'] ?? false);
            if ($this->bridge !== null && $this->bridge->isAvailable()) {
                if ($skipInstall) {
                    $session->addStep('install', 'skipped', 'No installable artifacts in bundle');
                } else {
                    $force = !empty($context['force']) || !empty($manifest->deploy()['force']);
                    $installed = $this->bridge->installPackage(
                        $manifest->archivePath(),
                        $session,
                        ['force' => $force],
                    );
                    if (!$installed) {
                        throw new PinrollException($session->lastInstallError() ?: 'Package install failed.');
                    }
                    $this->bridge->runPostInstall($manifest, $session);
                }
            }

            $baseUrl = isset($context['gate_url']) ? (string) $context['gate_url'] : null;
            $this->health->check($manifest, $session, $baseUrl);

            $strategy->commit($manifest, $session, ['staging' => $staging]);
            $session->markCommitted();

            $this->history->append(array_merge($session->toArray(), [
                'deploy_id' => $manifest->deployId(),
                'status' => 'committed',
            ]));
        } catch (\Throwable $e) {
            $this->rollback->rollback($manifest, $session);
            $session->markFailed($e->getMessage());
            throw $e;
        } finally {
            $this->lock->release();
        }
    }

    public function history(): RolloutHistory
    {
        return $this->history;
    }

    public function rollbackManager(): RollbackManager
    {
        return $this->rollback;
    }
}
