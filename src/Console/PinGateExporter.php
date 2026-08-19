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
            $zipPath = $this->createZip($target, [
                $entryPath => HostDir::GATE_ENTRY,
                $snippetPath => 'htaccess.snippet',
            ]);
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
        $bootstrap = (string) file_get_contents(self::TEMPLATE_DIR . '/bootstrap.php');
        $bootstrap = preg_replace('/^<\?php\s*(?:declare\(strict_types=1\);\s*)?/', '', $bootstrap) ?? $bootstrap;
        $exported = var_export($gateConfig, true);

        $contents = <<<PHP
<?php
declare(strict_types=1);

\$PINROLL_GATE = {$exported};

if (defined('PINROLL_GATE_AS_CONFIG')) {
    return \$PINROLL_GATE;
}

{$bootstrap}

pinroll_pingate_run(__DIR__, \$PINROLL_GATE);

PHP;

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
    private function createZip(string $target, array $files): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new PinrollException('ZipArchive is not available. Install the PHP zip extension.');
        }

        $zipPath = ProjectPaths::deployZip($this->paths, $target);
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
