<?php

namespace Pinoox\Pinroll\PinGate;

use Pinoox\Pinroll\Bridge\PlatformBootstrap;
use Pinoox\Pinroll\Contract\PathResolverInterface;
use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Release\ReleaseManifest;
use Pinoox\Pinroll\Console\PinGateExporter;
use Pinoox\Pinroll\Rollout\RolloutEngine;
use Pinoox\Pinroll\Rollout\RolloutSession;
use Pinoox\Pinroll\Support\Config;
use Pinoox\Pinroll\Support\IncomingRelease;
use Pinoox\Pinroll\Host\HookRunner;
use Pinoox\Pinroll\Host\RetentionPolicy;
use Pinoox\Pinion\HttpHandler;
use Pinoox\Pinion\Pinion;

final class PinGateHttpHandler
{
    private readonly PinGateAuth $auth;
    private readonly HttpHandler $pinion;

    public function __construct(
        private readonly Config $config,
        private readonly PathResolverInterface $paths,
        private readonly RolloutEngine $engine,
    ) {
        $this->auth = new PinGateAuth($config);
        Pinion::configure(['storage_path' => $config->storage('pinion')]);
        $this->pinion = Pinion::http(['destination' => (string) $config->get('incoming_path', 'pinroll/incoming')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(string $method, string $path, array $input = [], ?string $authorization = null, ?string $configFile = null): array
    {
        $this->authenticate($authorization, $configFile);
        $path = trim($path, '/');

        return match (true) {
            $method === 'POST' && $path === 'push/init' => $this->pinion->init($input),
            $method === 'POST' && $path === 'push/upload' => $this->pinion->upload($input, $input['chunk'] ?? null),
            $method === 'POST' && $path === 'push/complete' => $this->handleComplete($input),
            $method === 'POST' && ($path === 'install' || $path === 'apply') => $this->handleInstall($input),
            $method === 'GET' && $path === 'status' => $this->handleStatus($input),
            $method === 'GET' && $path === 'incoming' => $this->handleIncoming(),
            $method === 'POST' && $path === 'rollback' => $this->handleRollback($input),
            $method === 'POST' && $path === 'cleanup' => $this->handleCleanup($input),
            $method === 'GET' && $path === 'history' => $this->handleHistory(),
            default => throw new PinrollException('Unknown PinGate route: ' . $path, 404),
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    public function writeBootstrap(array $config): string
    {
        $export = (new PinGateExporter($this->paths))->export(
            (string) ($config['target'] ?? 'production'),
            $config,
        );

        return $export['index'];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function handleComplete(array $input): array
    {
        $result = $this->pinion->complete($input);
        if (!($result['success'] ?? false)) {
            return $result;
        }

        $meta = is_array($input['meta'] ?? null) ? $input['meta'] : [];
        $deployId = (string) ($meta['deploy_id'] ?? $input['deploy_id'] ?? '');

        if ($deployId !== '') {
            $this->applyUploadedRelease($deployId, (string) ($meta['checksum'] ?? ''));
        }

        return $result;
    }

    private function handleInstall(array $input): array
    {
        return $this->handleApply($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function handleApply(array $input): array
    {
        PlatformBootstrap::ensure($this->paths->root());

        $deployId = (string) ($input['deploy_id'] ?? '');
        $result = $this->applyUploadedRelease($deployId, '', false, $input);

        return ['deploy_id' => $result['deploy_id'], 'status' => 'applied'];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function handleStatus(array $input): array
    {
        $deployId = (string) ($input['deploy_id'] ?? '');
        $session = RolloutSession::load($this->config, $deployId);
        $base = $session?->toArray() ?? ['status' => 'unknown'];

        $ready = $this->platformReady();
        $base['platform'] = $ready;

        return $base;
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function platformReady(): array
    {
        try {
            PlatformBootstrap::ensure($this->paths->root());

            return ['ok' => true, 'message' => 'Pinx ready'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function handleRollback(array $input): array
    {
        PlatformBootstrap::ensure($this->paths->root());

        $deployId = (string) ($input['deploy_id'] ?? '');
        if ($deployId === '') {
            $deployId = $this->previousCommittedDeployId() ?? '';
        }

        if ($deployId === '') {
            throw new PinrollException(
                'No previous release to rollback to. Pass deploy_id or push an older .pinx first.',
            );
        }

        // Re-install previous package with force (allows version downgrade).
        $result = $this->applyUploadedRelease($deployId, '', true);

        return [
            'deploy_id' => $result['deploy_id'],
            'status' => 'rolled_back',
            'mode' => 'reapply_force',
        ];
    }

    private function previousCommittedDeployId(): ?string
    {
        $history = $this->engine->history()->all(50);
        $seen = 0;

        foreach ($history as $entry) {
            if (($entry['status'] ?? '') !== 'committed') {
                continue;
            }

            $id = (string) ($entry['deploy_id'] ?? $entry['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $seen++;
            // Skip current (first committed); return the previous one.
            if ($seen === 2) {
                return $id;
            }
        }

        // Fallback: second newest incoming archive
        $incoming = $this->config->storage((string) $this->config->get('incoming_path', 'pinroll/incoming'));
        $releases = IncomingRelease::list($incoming);

        return isset($releases[1]['id']) ? (string) $releases[1]['id'] : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function handleHistory(): array
    {
        return ['history' => $this->engine->history()->all()];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function handleCleanup(array $input): array
    {
        $keep = isset($input['keep']) ? (int) $input['keep'] : 3;
        $dryRun = !empty($input['dry_run']);

        return (new StorageCleaner($this->config))->clean([
            'keep' => $keep,
            'dry_run' => $dryRun,
            'incoming' => !array_key_exists('incoming', $input) || !empty($input['incoming']),
            'tmp' => !array_key_exists('tmp', $input) || !empty($input['tmp']),
            'staging' => !array_key_exists('staging', $input) || !empty($input['staging']),
            'sessions' => !array_key_exists('sessions', $input) || !empty($input['sessions']),
            'releases' => !array_key_exists('releases', $input) || !empty($input['releases']),
            'backups' => !array_key_exists('backups', $input) || !empty($input['backups']),
        ]);
    }

    /**
     * @return array{releases: list<array{id: string, path: string, size: int, mtime: int}>}
     */
    private function handleIncoming(): array
    {
        $incoming = $this->config->storage((string) $this->config->get('incoming_path', 'pinroll/incoming'));
        $releases = array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'path' => basename($row['path']),
            'size' => $row['size'],
            'mtime' => $row['mtime'],
        ], IncomingRelease::list($incoming));

        return ['releases' => $releases];
    }

    /**
     * @param array<string, mixed> $retentionInput
     * @return array{deploy_id: string}
     */
    private function applyUploadedRelease(
        string $deployId,
        string $checksum = '',
        bool $force = false,
        array $retentionInput = [],
    ): array {
        $incoming = $this->config->storage((string) $this->config->get('incoming_path', 'pinroll/incoming'));
        $archive = IncomingRelease::resolve($incoming, $deployId !== '' ? $deployId : null);
        $resolvedId = IncomingRelease::idFromArchive($archive);
        // FTP uploads .tar wrappers; PinxInstaller needs the inner .pinx (zip).
        $workDir = $this->config->storage('tmp/apply/' . $resolvedId);
        $installable = IncomingRelease::resolveInstallable($archive, $workDir);

        $manifest = ReleaseManifest::fromArray([
            'deploy_id' => $resolvedId,
            'archive_path' => $installable,
            'files_checksum' => $checksum,
            'deploy' => [
                'scope' => 'app',
                'health_checks' => ['/'],
                'force' => $force,
            ],
        ]);

        $session = RolloutSession::create(
            $this->config,
            'pingate',
            $force ? 'rollback' : 'incoming',
            'pinion',
        );

        $hostConfig = [
            'hooks' => is_array($this->config->get('hooks')) ? $this->config->get('hooks') : [],
            'keep' => array_key_exists('keep', $retentionInput)
                ? (int) $retentionInput['keep']
                : (int) $this->config->get('keep', 3),
            'store' => array_key_exists('store', $retentionInput)
                ? (string) $retentionInput['store']
                : (string) $this->config->get('store', 'remote'),
            'auto_clean' => array_key_exists('auto_clean', $retentionInput)
                ? (bool) $retentionInput['auto_clean']
                : (bool) $this->config->get('auto_clean', true),
        ];

        // On the host, cleanup only touches remote (host) storage.
        if ($hostConfig['store'] === 'both') {
            $hostConfig['store'] = 'remote';
        } elseif ($hostConfig['store'] === 'local') {
            $hostConfig['auto_clean'] = false;
        }

        $this->engine->apply($manifest, $session, [
            'gate_url' => $this->detectBaseUrl(),
            'force' => $force,
            'host' => $hostConfig,
        ]);

        return ['deploy_id' => $resolvedId];
    }

    private function authenticate(?string $authorization, ?string $configFile = null): void
    {
        $candidates = array_values(array_filter([
            $configFile,
            $this->paths->root() . '/pinroll/gate/pingate.php',
            $this->paths->root() . '/_pinoox/gate/pingate.php',
            $this->paths->root() . '/public/_pinoox/gate/pingate.php',
        ]));

        $gate = null;
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                /** @var array<string, mixed> $loaded */
                $loaded = require $candidate;
                $gate = $loaded;
                break;
            }
        }

        if (!is_array($gate)) {
            return;
        }

        $hash = (string) ($gate['token_hash'] ?? '');
        if ($hash !== '') {
            $this->auth->verifyBearer($authorization, $hash);
        }
    }

    private function detectBaseUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        return $scheme . '://' . $host;
    }
}
