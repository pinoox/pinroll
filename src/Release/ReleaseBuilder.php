<?php

namespace Pinoox\Pinroll\Release;

use Pinoox\Pinroll\Contract\PathResolverInterface;
use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Support\AppBuildPaths;
use Pinoox\Pinroll\Support\Config;
use Pinoox\Pinroll\Support\PushProgress;
use Pinoox\Pinroll\Support\TokenGenerator;

final class ReleaseBuilder
{
    public function __construct(
        private readonly Config $config,
        private readonly PathResolverInterface $paths,
    ) {
    }

    /**
     * @param array{build?: string|null, vendor?: bool}|null $profile
     * @return array{archive: string, manifest: ReleaseManifest}
     */
    public function build(ReleaseBundle $bundle, ?string $package = null, ?array $profile = null): array
    {
        $deployId = TokenGenerator::deployId();
        $outputDir = $this->config->storage('releases/' . $deployId);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $customBuild = is_array($profile) && !empty($profile['build']) ? (string) $profile['build'] : null;
        $steps = $customBuild !== null
            ? [['type' => 'custom', 'command' => $customBuild]]
            : $bundle->buildSteps();
        PushProgress::detail('Building bundle "' . $bundle->name() . '" (' . count($steps) . ' step(s))…');

        $artifacts = [];
        foreach ($steps as $index => $step) {
            $label = (string) ($step['command'] ?? $step['type'] ?? 'step');
            PushProgress::detail('Build step ' . ($index + 1) . '/' . count($steps) . ': ' . $label);
            $artifacts[] = $this->runBuildStep($step, $outputDir, $package);
        }

        PushProgress::detail('Packaging release archive…');
        $archive = $this->packageArtifacts($artifacts, $outputDir, $deployId);
        PushProgress::detail('Archive ready: ' . basename($archive) . ' (' . $this->formatBytes((int) filesize($archive)) . ')');
        $checksum = 'sha256:' . hash_file('sha256', $archive);

        $manifest = ReleaseManifest::fromArray([
            'deploy_id' => $deployId,
            'id' => $package ?? $bundle->name(),
            'bundle' => $bundle->name(),
            'scope' => $bundle->scope(),
            'archive_path' => $archive,
            'files_checksum' => $checksum,
            'built_at' => date('c'),
            'artifacts' => $artifacts,
            'deploy' => [
                'scope' => $bundle->scope(),
                'strategy' => 'auto',
                'rollback' => true,
                'health_checks' => ['/'],
                'post_install' => ['cache:build'],
                'destructive_migrations' => false,
                'timeout_sec' => 120,
                'skip_install' => $steps === [],
                'vendor' => (bool) ($profile['vendor'] ?? false),
            ],
        ]);

        $manifestPath = $outputDir . '/manifest.json';
        $manifest->write($manifestPath);

        return [
            'archive' => $archive,
            'manifest' => $manifest,
        ];
    }

    /**
     * @param array<string, mixed> $step
     * @return array<string, mixed>
     */
    private function runBuildStep(array $step, string $outputDir, ?string $package): array
    {
        $type = (string) ($step['type'] ?? 'app');
        $command = (string) ($step['command'] ?? '');
        $pinxOutput = null;

        if ($command !== '') {
            $command = str_replace('{{package}}', (string) $package, $command);
            [$command, $pinxOutput] = $this->augmentPinxBuildCommand($command, $package);
            $this->execPinoox($command, $outputDir);

            if (is_string($pinxOutput) && !is_file($pinxOutput)) {
                throw new PinrollException('Pinx package not found after build: ' . $pinxOutput);
            }
        }

        return [
            'type' => $type,
            'package' => $step['package'] ?? $package,
            'command' => $command,
            'pinx' => $pinxOutput,
        ];
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function augmentPinxBuildCommand(string $command, ?string $package): array
    {
        if ($package === null || $package === '' || !preg_match('/\bpinx:build\b/', $command)) {
            return [$command, null];
        }

        if (preg_match('/(?:^|\s)(?:-o|--output)(?:=|\s)/', $command)) {
            return [$command, null];
        }

        $root = $this->paths->root();
        $outputPath = AppBuildPaths::nextPinxOutput($root, $package);
        AppBuildPaths::ensureDir(dirname($outputPath));
        PushProgress::arrow('output: ' . AppBuildPaths::displayPath($root, $outputPath));

        return [$command . ' -o ' . escapeshellarg($outputPath), $outputPath];
    }

    private function packageArtifacts(array $artifacts, string $outputDir, string $deployId): string
    {
        $archive = $outputDir . '/' . $deployId . '.tar';

        if (class_exists(\PharData::class)) {
            $phar = new \PharData($archive);

            foreach ($artifacts as $artifact) {
                $pinx = $artifact['pinx'] ?? null;
                if (is_string($pinx) && is_file($pinx)) {
                    $phar->addFile($pinx, basename($pinx));
                }
            }

            $manifestFile = $outputDir . '/manifest.json';
            if (is_file($manifestFile)) {
                $phar->addFile($manifestFile, 'manifest.json');
            } else {
                $phar->addFromString(
                    'build.json',
                    json_encode(['artifacts' => $artifacts, 'deploy_id' => $deployId], JSON_PRETTY_PRINT),
                );
            }

            unset($phar);

            if (!is_file($archive)) {
                throw new PinrollException('Failed to create release archive.');
            }

            return $archive;
        }

        $fallback = $outputDir . '/' . $deployId . '.json';
        file_put_contents($fallback, json_encode(['artifacts' => $artifacts], JSON_PRETTY_PRINT));

        return $fallback;
    }

    private function execPinoox(string $command, string $outputDir): void
    {
        $root = $this->paths->root();
        $pinoox = is_file($root . '/pinoox') ? $root . '/pinoox' : 'pinoox';
        $full = 'php -d output_buffering=0 -d implicit_flush=1 '
            . escapeshellarg($pinoox) . ' --no-interaction ' . $command;

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($full, $descriptors, $pipes, $root);
        if (!is_resource($process)) {
            throw new PinrollException('Failed to start build command: ' . $full);
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $lastActivity = time();
        $exitCode = 1;

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            if ($this->flushStreamLines($stdout) || $this->flushStreamLines($stderr)) {
                $lastActivity = time();
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = (int) $status['exitcode'];
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                $this->flushStreamLines($stdout);
                $this->flushStreamLines($stderr);
                if ($stdout !== '') {
                    PushProgress::stream($stdout);
                }
                if ($stderr !== '') {
                    PushProgress::stream($stderr);
                }
                break;
            }

            if (time() - $lastActivity >= 8) {
                PushProgress::warn('still building (this can take a few minutes)');
                $lastActivity = time();
            }

            usleep(50_000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($exitCode !== 0) {
            throw new PinrollException('Build command failed (exit ' . $exitCode . '): ' . $full);
        }
    }

    private function flushStreamLines(string &$buffer): bool
    {
        $wrote = false;

        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 1);
            PushProgress::stream($line);
            $wrote = true;
        }

        return $wrote;
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
