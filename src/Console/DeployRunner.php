<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Release\ReleaseBundle;
use Pinoox\Pinroll\Release\ReleaseManifest;
use Pinoox\Pinroll\Rollout\RolloutSession;
use Pinoox\Pinroll\Support\HostDir;
use Pinoox\Pinroll\Support\IncomingRelease;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\PinrollAutoloader;
use Pinoox\Pinroll\Support\ProjectPaths;
use Pinoox\Pinroll\Support\PushProgress;
use Pinoox\Pinroll\Support\PushSteps;
use Pinoox\Pinroll\Support\ThemePaths;
use Pinoox\Pinroll\Support\TokenGenerator;
use Pinoox\Pinroll\Target\PinGateClient;
use Pinoox\Pinroll\Host\HookRunner;
use Pinoox\Pinroll\Host\HostGate;
use Pinoox\Pinroll\Host\LocalArchiveStore;
use Pinoox\Pinroll\Host\RetentionPolicy;
use Pinoox\Pinroll\Transport\FtpUploader;
use Pinoox\Pinroll\Transport\TransportConnector;

final class DeployRunner
{
    public function __construct(
        private readonly ?string $projectRoot = null,
    ) {
        $root = $this->projectRoot ?? (defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd());
        PinrollAutoloader::register((string) $root);
        Pinroll::boot(new NativePathResolver((string) $root));
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function deploy(string $targetName, array $options = []): array
    {
        if (!empty($options['bundle'])) {
            return $this->deployLegacyBundle($targetName, $options);
        }

        $via = (string) ($options['via'] ?? $options['transport'] ?? '');
        $target = Pinroll::hosts()->resolve($targetName, $via !== '' ? $via : null);
        $rawHost = Pinroll::hosts()->raw($targetName);
        $plan = PushRuleResolver::resolve($target, $options);

        if ($plan['app'] && $plan['apps'] === []) {
            throw new PinrollException('No apps found in apps/. Install an app or set apps[] in config.');
        }

        $transportName = (string) $target['transport'];
        $root = Pinroll::paths()->root();
        $shouldApply = !empty($options['apply']);
        $session = RolloutSession::create(Pinroll::config(), $targetName, $plan['rule'], $transportName);

        HookRunner::run($rawHost, ['before_push'], $session, $root, false);

        PushSteps::outline($this->stepPlan($plan, $transportName, $shouldApply));

        /** @var list<array{archive: string, manifest: ReleaseManifest}> $builds */
        $builds = [];

        if ($plan['app']) {
            foreach ($plan['apps'] as $package) {
                PushSteps::start('Build ' . $package . ' (pinx)');
                $bundle = ReleaseBundle::resolveAuto(
                    Pinroll::config(),
                    Pinroll::paths(),
                    $package,
                    null,
                    isset($options['bundle']) && (string) $options['bundle'] !== ''
                        ? (string) $options['bundle']
                        : null,
                );
                $profile = [
                    'vendor' => $plan['vendor'],
                    'build' => 'pinx:build ' . $package . ' --yes --no-ansi',
                ];
                $result = Pinroll::builder()->build($bundle, $package, $profile);
                PushSteps::done(basename((string) $result['archive']));
                $builds[] = $result;
            }
        }

        if ($this->needsUpload($plan)) {
            PushSteps::start('Connect via ' . $transportName);
            TransportConnector::test($target);
            PushSteps::done($this->connectLabel($target, $transportName));
        }

        $transport = Pinroll::transports()->resolve($target);

        if ($plan['app']) {
            foreach ($builds as $index => $result) {
                $package = $plan['apps'][$index] ?? 'app';
                PushSteps::start('Upload ' . $package . ' via ' . $transportName);
                $transport->send((string) $result['archive'], $result['manifest'], $target, $session);
                PushSteps::done();

                $localCopy = LocalArchiveStore::keep((string) $result['archive'], $result['manifest'], $target);
                if ($localCopy !== null) {
                    $session->addStep('store', 'ok', 'Local archive kept: ' . basename($localCopy));
                    PushProgress::detail('Local store: ' . basename($localCopy));
                }
            }
        }

        if ($plan['vendor'] && !$plan['app'] && $transportName === 'ftp') {
            $this->uploadVendorFtp($target, $root, $session);
        }

        if ($plan['theme']) {
            if ($transportName !== 'ftp') {
                throw new PinrollException('Theme upload is supported on FTP only for now.');
            }

            $this->uploadThemeFtp($target, $plan['apps'], $root, $session);
        }

        HookRunner::run($rawHost, ['after_push'], $session, $root, false);

        if ($shouldApply && $plan['app'] && $builds !== []) {
            $applier = new ReleaseApplier();

            foreach ($builds as $result) {
                $deployId = $result['manifest']->deployId();
                PushSteps::start('Install ' . $deployId . ' via PinGate');
                $applier->applyOnTarget($target, $rawHost, $deployId, $session);
                PushSteps::done();
            }
        }

        return array_merge($session->toArray(), [
            'deploy_id' => $builds !== [] ? $builds[array_key_last($builds)]['manifest']->deployId() : null,
        ]);
    }

    /**
     * @param array{apps: list<string>, app: bool, vendor: bool, theme: bool} $plan
     * @return list<string>
     */
    private function stepPlan(array $plan, string $transportName, bool $apply = false): array
    {
        $steps = [];

        if ($plan['app']) {
            foreach ($plan['apps'] as $package) {
                $steps[] = 'Build ' . $package . ' (pinx)';
            }
        }

        if ($this->needsUpload($plan)) {
            $steps[] = 'Connect via ' . $transportName;
        }

        if ($plan['app']) {
            foreach ($plan['apps'] as $package) {
                $steps[] = 'Upload ' . $package . ' via ' . $transportName;
            }
        }

        if ($plan['vendor'] && !$plan['app']) {
            $steps[] = 'Upload vendor/ via ' . $transportName;
        }

        if ($plan['theme']) {
            $steps[] = 'Upload theme dist via ' . $transportName;
        }

        if ($apply && $plan['app']) {
            $steps[] = 'Install release via PinGate';
        }

        return $steps;
    }

    /**
     * @param array{app: bool, vendor: bool, theme: bool} $plan
     */
    private function needsUpload(array $plan): bool
    {
        return $plan['app'] || $plan['vendor'] || $plan['theme'];
    }

    /**
     * @param array<string, mixed> $target
     */
    private function connectLabel(array $target, string $transportName): string
    {
        return match ($transportName) {
            'pinion' => (string) ($target['gate_url'] ?? 'PinGate'),
            default => (string) ($target['host'] ?? 'connected'),
        };
    }

    /**
     * @param array<string, mixed> $target
     */
    private function uploadVendorFtp(array $target, string $root, RolloutSession $session): void
    {
        PushSteps::start('Upload vendor/ via ftp');
        $connection = $this->openFtp($target);
        try {
            $vendorLocal = rtrim($root, '/') . '/vendor';
            if (!is_dir($vendorLocal)) {
                throw new PinrollException('vendor/ not found — run composer install first.');
            }

            $prefix = $this->remotePrefix($target);
            $count = (new FtpUploader())->uploadDirectory($connection, $vendorLocal, $prefix . 'vendor', 'vendor');
            $session->addStep('vendor', 'ok', 'vendor/ synced (' . $count . ' files)');
            PushSteps::done($count . ' files');
        } finally {
            if (is_resource($connection)) {
                ftp_close($connection);
            }
        }
    }

    /**
     * @param list<string> $apps
     * @param array<string, mixed> $target
     */
    private function uploadThemeFtp(array $target, array $apps, string $root, RolloutSession $session): void
    {
        PushSteps::start('Upload theme dist via ftp');
        $connection = $this->openFtp($target);
        try {
            $prefix = $this->remotePrefix($target);
            $uploaded = 0;
            $totalFiles = 0;

            foreach ($apps as $package) {
                foreach (ThemePaths::distFolders($root, $package) as $folder) {
                    $label = $folder['package'] . '/' . $folder['theme'];
                    PushProgress::arrow($label);
                    $count = (new FtpUploader())->uploadDirectory($connection, $folder['local'], $prefix . $folder['remote'], $label);
                    $totalFiles += $count;
                    $uploaded++;
                }
            }

            if ($uploaded === 0) {
                throw new PinrollException('No theme dist folders found. Build theme assets first.');
            }

            $session->addStep('theme', 'ok', $uploaded . ' theme folder(s), ' . $totalFiles . ' files');
            PushSteps::done($uploaded . ' folder(s), ' . $totalFiles . ' files');
        } finally {
            if (is_resource($connection)) {
                ftp_close($connection);
            }
        }
    }

    /**
     * @param array<string, mixed> $target
     * @return resource
     */
    private function openFtp(array $target)
    {
        $host = (string) ($target['host'] ?? '');
        $user = (string) ($target['user'] ?? '');
        $password = (string) ($target['password'] ?? '');

        return (new FtpUploader())->connect($host, $user, $password);
    }

    /**
     * @param array<string, mixed> $target
     */
    private function remotePrefix(array $target): string
    {
        $deployRoot = HostDir::deployRoot(HostDir::fromTarget($target));

        return $deployRoot === '.' ? '' : $deployRoot . '/';
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function deployLegacyBundle(string $targetName, array $options): array
    {
        $via = (string) ($options['via'] ?? $options['transport'] ?? '');
        $target = Pinroll::hosts()->resolve($targetName, $via !== '' ? $via : null);
        $bundleName = isset($options['bundle']) ? (string) $options['bundle'] : '';
        $package = isset($options['package']) ? (string) $options['package'] : null;
        $transportName = (string) $target['transport'];

        $bundle = ReleaseBundle::resolveAuto(
            Pinroll::config(),
            Pinroll::paths(),
            $package,
            null,
            $bundleName !== '' ? $bundleName : null,
        );
        $session = RolloutSession::create(Pinroll::config(), $targetName, $bundle->name(), $transportName);
        $build = Pinroll::builder()->build($bundle, $package);
        $transport = Pinroll::transports()->resolve($target);
        $transport->send((string) $build['archive'], $build['manifest'], $target, $session);

        if ($transportName === 'local') {
            Pinroll::engine()->apply($build['manifest'], $session, [
                'gate_url' => $target['gate_url'] ?? null,
                'public_key' => $target['public_key'] ?? null,
            ]);
        }

        return $session->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function build(?string $bundleName = null, ?string $package = null): array
    {
        $bundle = ReleaseBundle::resolveAuto(
            Pinroll::config(),
            Pinroll::paths(),
            $package,
            null,
            $bundleName,
        );
        $result = Pinroll::builder()->build($bundle, $package);

        return [
            'archive' => $result['archive'],
            'manifest' => $result['manifest']->toArray(),
        ];
    }

    /**
     * Build PinGate files. Default: no zip. When FTP is configured, upload then delete local artifacts.
     *
     * @return array<string, mixed>
     */
    public function initGate(
        string $targetName,
        bool $zip = false,
        ?string $hostDir = null,
        ?string $gateUrl = null,
        bool $rotateToken = false,
        bool $upload = true,
        bool $withVendor = false,
    ): array {
        $target = Pinroll::hosts()->resolve($targetName);
        $raw = Pinroll::hosts()->raw($targetName);
        $keys = ProjectPreparer::envKeysForTarget($targetName);

        $existing = HostGate::credentials($raw);
        $token = (!$rotateToken && $existing['token'] !== '')
            ? $existing['token']
            : TokenGenerator::token();
        $tokenReused = !$rotateToken && $existing['token'] !== '' && $token === $existing['token'];
        $hash = TokenGenerator::hashToken($token);

        $dir = $hostDir !== null ? HostDir::normalize($hostDir) : HostDir::fromTarget($target);

        // Always keep local files until FTP upload finishes (then cleanup). Zip is optional.
        PushProgress::arrow('Building PinGate files…');
        $exporter = new PinGateExporter(Pinroll::paths());
        $export = $exporter->export($targetName, [
            'target' => $targetName,
            'token_hash' => $hash,
            'created_at' => date('c'),
            'dir' => $dir,
            'platform_root' => '..',
        ], $zip, $dir, keepLocal: true, withVendor: $withVendor);

        $resolvedUrl = $gateUrl !== null ? rtrim($gateUrl, '/') : '';
        $gateUrlFromUser = $resolvedUrl !== '';
        if ($resolvedUrl === '') {
            $existingUrl = $existing['url'] !== '' ? $existing['url'] : '';
            if ($existingUrl !== '') {
                $resolvedUrl = rtrim($existingUrl, '/');
                $gateUrlFromUser = true;
            } else {
                $resolvedUrl = rtrim(HostGate::exampleUrl($dir), '/');
            }
        }

        $envPath = Pinroll::paths()->root() . '/.env';
        EnvFileWriter::merge($envPath, [
            $keys['url'] => $resolvedUrl,
            $keys['token'] => $token,
        ]);

        $uploaded = false;
        $uploadInfo = null;
        if ($upload && GateDeployer::canUpload($target)) {
            $transport = (string) ($target['transport'] ?? 'ftp');
            PushProgress::arrow('Uploading PinGate via ' . strtoupper($transport) . '…');
            $uploadInfo = (new GateDeployer())->upload(
                $target,
                (string) $export['entry'],
                (string) $export['gate_dir'],
            );
            $uploaded = true;
            $exporter->cleanupLocalArtifacts(
                (string) $export['entry'],
                ProjectPaths::dir(Pinroll::paths()) . '/htaccess.snippet',
                (string) $export['gate_dir'],
            );
            $zipPath = ProjectPaths::deployZip(Pinroll::paths(), $targetName);
            if (is_file($zipPath)) {
                @unlink($zipPath);
            }
            $export['zip'] = null;
        } elseif ($zip) {
            // Zip-only manual upload: drop loose files, keep the archive.
            $exporter->cleanupLocalArtifacts(
                (string) $export['entry'],
                ProjectPaths::dir(Pinroll::paths()) . '/htaccess.snippet',
                (string) $export['gate_dir'],
            );
        }

        return [
            'bootstrap' => $export['index'],
            'gate_dir' => ($uploaded || $zip) ? null : $export['gate_dir'],
            'entry' => ($uploaded || $zip) ? null : $export['entry'],
            'zip' => $export['zip'],
            'dir' => $dir,
            'token' => $token,
            'token_reused' => $tokenReused,
            'env_path' => $envPath,
            'gate_url' => $resolvedUrl,
            'gate_url_is_example' => !$gateUrlFromUser,
            'token_key' => $keys['token'],
            'url_key' => $keys['url'],
            'extract_to' => HostDir::extractGuidePath($dir),
            'uploaded' => $uploaded,
            'upload' => $uploadInfo,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(string $targetName, ?string $deployId = null): array
    {
        if ($deployId !== null && $deployId !== '') {
            $session = RolloutSession::load(Pinroll::config(), $deployId);

            return $session?->toArray() ?? ['status' => 'unknown'];
        }

        return [
            'target' => Pinroll::hosts()->resolve($targetName),
            'history' => Pinroll::engine()->history()->all(10),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(?string $targetName = null): array
    {
        return Pinroll::engine()->history()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function rollback(string $targetName, ?string $deployId = null): array
    {
        $resolved = Pinroll::hosts()->resolve($targetName);
        $raw = Pinroll::hosts()->raw($targetName);
        $transport = (string) ($resolved['transport'] ?? '');

        if ($transport === 'local') {
            return $this->rollbackLocal($targetName, $deployId);
        }

        $settings = RetentionPolicy::settings(array_merge($raw, $resolved));
        $store = $settings['store'];

        if ($store === 'local' || $store === 'both') {
            $rollbackId = $this->resolveLocalRollbackDeployId($deployId);
            $archive = $this->localArchiveForRollback($rollbackId);

            if ($archive !== null) {
                return $this->rollbackViaLocalArchive($targetName, $resolved, $raw, $archive, $rollbackId);
            }
        }

        $gate = HostGate::credentials($raw);
        $gateUrl = $gate['url'] !== '' ? $gate['url'] : (string) ($resolved['gate_url'] ?? '');
        $token = $gate['token'] !== '' ? $gate['token'] : (string) ($resolved['token'] ?? '');

        if ($gateUrl === '' || $token === '') {
            throw new PinrollException(implode("\n", HostGate::setupGuide($targetName)));
        }

        PushProgress::arrow(
            'PinGate rollback' . ($deployId !== null && $deployId !== '' ? ': ' . $deployId : ' (previous release)'),
        );

        $result = PinGateClient::rollback($gateUrl, $token, $deployId ?? '');

        return [
            'target' => $targetName,
            'host' => $targetName,
            'channel' => 'PinGate',
            'status' => (string) ($result['status'] ?? 'rolled_back'),
            'deploy_id' => (string) ($result['deploy_id'] ?? $deployId ?? ''),
            'mode' => (string) ($result['mode'] ?? 'reapply_force'),
            'result' => $result,
        ];
    }

    /**
     * @param array<string, mixed> $resolved
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function rollbackViaLocalArchive(
        string $targetName,
        array $resolved,
        array $raw,
        string $archive,
        string $deployId,
    ): array {
        $root = Pinroll::paths()->root();
        $session = RolloutSession::create(Pinroll::config(), $targetName, 'rollback', (string) ($resolved['transport'] ?? 'ftp'));

        HookRunner::run($raw, ['before_rollback'], $session, $root, false);

        PushProgress::arrow('Re-push local archive: ' . basename($archive));
        $manifest = ReleaseManifest::fromArray([
            'deploy_id' => $deployId,
            'archive_path' => $archive,
            'deploy' => ['scope' => 'app', 'health_checks' => ['/']],
        ]);

        $transport = Pinroll::transports()->resolve($resolved);
        $transport->send($archive, $manifest, $resolved, $session);

        PushProgress::arrow('Install rolled-back release');
        (new ReleaseApplier())->applyOnTarget($resolved, $raw, $deployId, $session);

        HookRunner::run($raw, ['after_rollback'], $session, $root, false);

        return array_merge($session->toArray(), [
            'target' => $targetName,
            'host' => $targetName,
            'channel' => 'local-archive',
            'status' => 'rolled_back',
            'deploy_id' => $deployId,
            'mode' => 're-push+install',
            'archive' => $archive,
        ]);
    }

    private function resolveLocalRollbackDeployId(?string $deployId): string
    {
        $incoming = Pinroll::config()->storage((string) Pinroll::config()->get('incoming_path', 'pinroll/incoming'));
        $releases = IncomingRelease::list($incoming);

        if ($releases === []) {
            throw new PinrollException('No local archives in ' . $incoming . '. Run pinroll:push first.');
        }

        if ($deployId !== null && $deployId !== '') {
            foreach ($releases as $release) {
                if ($release['id'] === $deployId || str_contains($release['path'], $deployId)) {
                    return $release['id'];
                }
            }

            throw new PinrollException('Local archive not found: ' . $deployId);
        }

        if (count($releases) < 2) {
            throw new PinrollException(
                'Need at least two local archives for rollback. Found ' . count($releases) . ' in ' . $incoming . '.',
            );
        }

        return $releases[1]['id'];
    }

    private function localArchiveForRollback(string $deployId): ?string
    {
        try {
            $incoming = Pinroll::config()->storage((string) Pinroll::config()->get('incoming_path', 'pinroll/incoming'));

            return IncomingRelease::resolve($incoming, $deployId);
        } catch (PinrollException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function rollbackLocal(string $targetName, ?string $deployId = null): array
    {
        $session = $deployId
            ? RolloutSession::load(Pinroll::config(), $deployId)
            : RolloutSession::create(Pinroll::config(), $targetName, 'rollback', 'internal');

        if ($session === null) {
            $session = RolloutSession::create(Pinroll::config(), $targetName, 'rollback', 'internal');
        }

        $manifest = ReleaseManifest::fromArray(['deploy_id' => $deployId ?? '', 'archive_path' => '']);
        Pinroll::engine()->rollbackManager()->rollback($manifest, $session);

        return $session->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function apply(?string $deployId = null): array
    {
        $incoming = Pinroll::config()->storage((string) Pinroll::config()->get('incoming_path', 'pinroll/incoming'));
        $archive = IncomingRelease::resolve($incoming, $deployId);
        $resolvedId = IncomingRelease::idFromArchive($archive);
        $workDir = Pinroll::config()->storage('tmp/apply/' . $resolvedId);
        $installable = IncomingRelease::resolveInstallable($archive, $workDir);

        $manifest = ReleaseManifest::fromArray([
            'deploy_id' => $resolvedId,
            'archive_path' => $installable,
            'deploy' => ['scope' => 'app', 'health_checks' => ['/']],
        ]);

        $session = RolloutSession::create(Pinroll::config(), 'local', 'apply', 'internal');
        Pinroll::engine()->apply($manifest, $session);

        return array_merge($session->toArray(), [
            'deploy_id' => $resolvedId,
            'archive' => $archive,
            'installable' => $installable,
        ]);
    }
}
