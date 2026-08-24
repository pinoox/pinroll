<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Contract\PathResolverInterface;
use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Support\HostDir;
use Pinoox\Pinroll\Support\ProjectPaths;
use Pinoox\Pinroll\Support\PushProgress;
use ZipArchive;

final class PinGateExporter
{
    private const TEMPLATE_DIR = __DIR__ . '/../../resources/pingate';

    public function __construct(
        private readonly PathResolverInterface $paths,
    ) {
    }

    /**
     * @param array<string, mixed> $gateConfig
     * @param array<string, string> $extraFiles local path => path inside zip
     * @return array{
     *     gate_dir: string,
     *     entry: string,
     *     config: string,
     *     index: string,
     *     zip: string|null,
     *     dir: string
     * }
     */
    public function export(
        string $target,
        array $gateConfig,
        bool $zip = true,
        ?string $hostDir = null,
        bool $keepLocal = false,
        bool $withVendor = false,
        array $extraFiles = [],
        bool $kit = false,
    ): array {
        unset($withVendor);
        $hostDir = HostDir::normalize($hostDir ?? (string) ($gateConfig['dir'] ?? $gateConfig['host_dir'] ?? $gateConfig['install'] ?? ''));

        $workDir = ProjectPaths::workDir($this->paths);
        if (!is_dir($workDir) && !mkdir($workDir, 0755, true) && !is_dir($workDir)) {
            throw new PinrollException('Unable to create Pinroll work directory: ' . $workDir);
        }

        $gateConfig['dir'] = $hostDir;
        unset($gateConfig['host_dir'], $gateConfig['install'], $gateConfig['platform_root']);

        $entryPath = $workDir . '/' . HostDir::GATE_ENTRY;
        $this->writeSingleEntry($entryPath, $gateConfig);

        $snippetPath = $workDir . '/htaccess.snippet';
        file_put_contents($snippetPath, self::htaccessSnippetContent($hostDir));

        $zipPath = null;
        if ($zip) {
            $files = [
                $entryPath => HostDir::GATE_ENTRY,
                $snippetPath => 'htaccess.snippet',
            ];
            foreach ($extraFiles as $source => $nameInZip) {
                if (is_string($source) && is_string($nameInZip) && is_file($source)) {
                    $files[$source] = $nameInZip;
                }
            }
            $zipPath = $this->createZip($target, $files, $kit);
        }

        if ($zip && !$keepLocal) {
            $this->cleanupLocalArtifacts($entryPath, $snippetPath, null);
        }

        return [
            'gate_dir' => $workDir,
            'index' => $entryPath,
            'entry' => $entryPath,
            'config' => $entryPath,
            'zip' => $zipPath,
            'dir' => $hostDir,
        ];
    }

    /**
     * @param array<string, mixed> $gateConfig
     */
    private function writeSingleEntry(string $destination, array $gateConfig): void
    {
        $samplePath = self::TEMPLATE_DIR . '/pingate.php';
        if (!is_file($samplePath)) {
            throw new PinrollException(
                'PinGate sample is required: resources/pingate/pingate.php is missing. Reinstall pinoox/pinroll.',
            );
        }

        $sample = (string) file_get_contents($samplePath);
        $needle = '$PINROLL_GATE = [];';
        $pos = strpos($sample, $needle);
        if ($pos === false) {
            throw new PinrollException(
                'PinGate sample is invalid: expected $PINROLL_GATE = []; in resources/pingate/pingate.php.',
            );
        }

        $contents = substr_replace($sample, '$PINROLL_GATE = ' . var_export($gateConfig, true) . ';', $pos, strlen($needle));
        if (file_put_contents($destination, $contents) === false) {
            throw new PinrollException('Unable to write PinGate file: ' . $destination);
        }
    }

    public static function htaccessSnippetContent(?string $hostDir = null): string
    {
        $web = HostDir::webPath($hostDir);
        $prefix = $web === '' ? '' : $web . '/';

        return <<<HTACCESS
# Pinroll — paste into host .htaccess before front-controller (only if check returns HTML)
RewriteRule ^{$prefix}pingate\\.php\$ - [L]

HTACCESS;
    }

    /**
     * @param array<string, string> $files
     */
    private function createZip(string $target, array $files, bool $kit = false): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new PinrollException('ZipArchive is not available. Install the PHP zip extension.');
        }

        $zipPath = $kit
            ? ProjectPaths::kitZip($this->paths, $target)
            : ProjectPaths::deployZip($this->paths, $target);
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new PinrollException('Unable to create zip archive: ' . $zipPath);
        }

        foreach ($files as $source => $nameInZip) {
            if (!is_file($source)) {
                throw new PinrollException('Missing file for zip: ' . $source);
            }

            $zip->addFile($source, $nameInZip);
        }

        $zip->close();

        return $zipPath;
    }

    public function cleanupLocalArtifacts(?string $entryPath = null, ?string $snippetPath = null, ?string $gateDir = null): void
    {
        $workDir = ProjectPaths::workDir($this->paths);
        $entryPath ??= $workDir . '/' . HostDir::GATE_ENTRY;
        $snippetPath ??= $workDir . '/htaccess.snippet';

        if (is_file($entryPath)) {
            unlink($entryPath);
        }

        if (is_file($snippetPath)) {
            unlink($snippetPath);
        }

        $legacyGate = ProjectPaths::dir($this->paths) . '/gate';
        if (is_dir($legacyGate)) {
            $this->removeDir($legacyGate);
        }

        $legacyLocal = rtrim($this->paths->root(), '/') . '/pinroll/gate';
        if (is_dir($legacyLocal)) {
            $this->removeDir($legacyLocal);
        }

        unset($gateDir);
    }

    private function removeDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }

        @rmdir($dir);
    }
}
