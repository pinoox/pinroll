<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Host\HostGate;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Release\ReleaseManifest;
use Pinoox\Pinroll\Rollout\RolloutSession;
use Pinoox\Pinroll\Support\PushProgress;
use Pinoox\Pinroll\Support\SyncPathValidator;
use Pinoox\Pinroll\Target\PinGateClient;
use Pinoox\Pinroll\Transport\FtpTransport;
use Pinoox\Pinroll\Transport\PinionTransport;
use Pinoox\Pinroll\Transport\SshTransport;

/**
 * Zip a local folder, upload via ftp/ssh/pinion, extract on host through PinGate POST ?route=sync.
 */
final class PathSyncer
{
    /**
     * @return array{
     *     host: string,
     *     local: string,
     *     remote: string,
     *     files: int,
     *     transport?: string,
     *     deploy_id?: string,
     *     zip?: string,
     *     extract?: array<string, mixed>
     * }
     */
    public function sync(
        string $hostName,
        string $localPath,
        string $remoteRelative,
        ?string $projectRoot = null,
        bool $dryRun = false,
        ?string $via = null,
    ): array {
        $root = $projectRoot ?? Pinroll::paths()->root();
        $local = SyncPathValidator::localDir($localPath, $root);
        $remoteRelative = SyncPathValidator::remoteRelative($remoteRelative);

        $raw = Pinroll::hosts()->raw($hostName);
        $resolved = Pinroll::hosts()->resolve($hostName, $via);
        $transport = (string) ($resolved['transport'] ?? 'ftp');

        if ($dryRun) {
            $files = count((new PathArchiveBuilder())->collectFiles($local));

            return [
                'host' => $hostName,
                'local' => $local,
                'remote' => $remoteRelative,
                'files' => $files,
                'transport' => $transport,
            ];
        }

        GateMaintainer::ensureBeforeDeploy($hostName, $resolved, $raw);

        $gate = HostGate::credentials($raw);
        $gateUrl = $gate['url'] !== '' ? $gate['url'] : (string) ($resolved['gate_url'] ?? '');
        $token = $gate['token'] !== '' ? $gate['token'] : (string) ($resolved['token'] ?? '');
        if ($gateUrl === '' || $token === '') {
            throw new PinrollException(
                'PinGate URL/token missing. Run php pinoox pinroll:kit or pinroll:connect first.',
            );
        }

        PushProgress::arrow('Packing ' . basename($local) . ' → ' . $remoteRelative);
        $archive = (new PathArchiveBuilder())->build($local, $remoteRelative, $root);
        $zipPath = $archive['zip'];
        $deployId = $archive['deploy_id'];

        try {
            PushProgress::arrow('Upload sync zip via ' . $transport . ' (' . $this->formatBytes($archive['bytes']) . ')');
            $this->uploadArchive($resolved, $zipPath, $deployId, $transport);

            PushProgress::arrow('PinGate extract → ' . $remoteRelative);
            $extract = PinGateClient::extractSync($gateUrl, $token, [
                'deploy_id' => $deployId,
                'target' => $remoteRelative,
                'delete_zip' => true,
            ]);
        } finally {
            if (is_file($zipPath)) {
                @unlink($zipPath);
            }
        }

        PushProgress::detail('Synced ' . $archive['files'] . ' file(s) via zip');

        return [
            'host' => $hostName,
            'local' => $local,
            'remote' => $remoteRelative,
            'files' => $archive['files'],
            'transport' => $transport,
            'deploy_id' => $deployId,
            'zip' => 'storage/pinroll/incoming/sync-' . $deployId . '.zip',
            'extract' => $extract,
        ];
    }

    /**
     * @param array<string, mixed> $resolved
     */
    private function uploadArchive(array $resolved, string $zipPath, string $deployId, string $transport): void
    {
        $manifest = ReleaseManifest::fromArray([
            'id' => $deployId,
            'deploy_id' => $deployId,
            'checksum' => hash_file('sha256', $zipPath) ?: '',
            'deploy' => ['scope' => 'sync'],
        ]);
        $session = RolloutSession::create(Pinroll::config(), (string) ($resolved['name'] ?? 'production'), 'path-sync', $transport);

        $driver = match ($transport) {
            'ssh' => new SshTransport(Pinroll::config()),
            'pinion' => new PinionTransport(Pinroll::config()),
            'local' => null,
            default => new FtpTransport(Pinroll::config()),
        };

        if ($transport === 'local') {
            $incoming = rtrim(Pinroll::paths()->root(), '/') . '/storage/pinroll/incoming';
            if (!is_dir($incoming) && !mkdir($incoming, 0755, true) && !is_dir($incoming)) {
                throw new PinrollException('Unable to create incoming directory.');
            }
            $dest = $incoming . '/sync-' . $deployId . '.zip';
            if (!@copy($zipPath, $dest)) {
                throw new PinrollException('Unable to copy sync zip into local incoming.');
            }

            return;
        }

        if ($driver === null) {
            throw new PinrollException('Unknown transport: ' . $transport);
        }

        // Transports store basename(archive); ensure remote name is sync-{id}.zip
        $named = dirname($zipPath) . '/sync-' . $deployId . '.zip';
        if ($named !== $zipPath) {
            if (!@rename($zipPath, $named) && !@copy($zipPath, $named)) {
                throw new PinrollException('Unable to stage sync zip for upload.');
            }
            if (is_file($zipPath) && $zipPath !== $named) {
                @unlink($zipPath);
            }
            $zipPath = $named;
        }

        $driver->send($zipPath, $manifest, $resolved, $session);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / (1024 * 1024), 1) . ' MB';
    }
}
