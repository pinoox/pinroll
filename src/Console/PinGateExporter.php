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
    ): array {
        $hostDir = HostDir::normalize($hostDir ?? (string) ($gateConfig['dir'] ?? $gateConfig['host_dir'] ?? $gateConfig['install'] ?? ''));

        $pinrollDir = ProjectPaths::dir($this->paths);
        $gateDir = ProjectPaths::gateDir($this->paths);

        if (!is_dir($gateDir) && !mkdir($gateDir, 0755, true) && !is_dir($gateDir)) {
            throw new PinrollException('Unable to create pinroll gate directory: ' . $gateDir);
        }

        $gateConfig['dir'] = $hostDir;
        unset($gateConfig['host_dir'], $gateConfig['install']);

        $configPath = $gateDir . '/pingate.php';
        file_put_contents($configPath, '<?php return ' . var_export($gateConfig, true) . ';' . "\n");

        $indexPath = $this->copyTemplate('index.php', $gateDir . '/index.php');
        $bootstrapPath = $this->copyTemplate('bootstrap.php', $gateDir . '/bootstrap.php');
        $htaccessPath = $this->copyTemplate('gate.htaccess', $gateDir . '/.htaccess');
        $this->bundleGateVendor($gateDir);
        $entryPath = $pinrollDir . '/' . HostDir::GATE_ENTRY;
        $this->copyTemplate('entry.php', $entryPath);

        $snippetPath = $pinrollDir . '/htaccess.snippet';
        file_put_contents($snippetPath, self::htaccessSnippetContent($hostDir));

        $zipPath = null;
        if ($zip) {
            $zipPath = $this->createZip($target, [
                $entryPath => HostDir::GATE_ENTRY,
                $bootstrapPath => HostDir::GATE_DIR . '/bootstrap.php',
                $indexPath => HostDir::GATE_DIR . '/index.php',
                $configPath => HostDir::GATE_DIR . '/pingate.php',
                $htaccessPath => HostDir::GATE_DIR . '/.htaccess',
                $snippetPath => 'htaccess.snippet',
            ], [$gateDir . '/vendor' => HostDir::GATE_DIR . '/vendor']);
        }

        if ($zip && !$keepLocal) {
            $this->removeLocalDeployFiles($entryPath, $snippetPath, $gateDir);
        }

        return [
            'gate_dir' => $gateDir,
            'index' => $indexPath,
            'entry' => $entryPath,
            'config' => $configPath,
            'zip' => $zipPath,
            'dir' => $hostDir,
        ];
    }

    public static function htaccessSnippetContent(?string $hostDir = null): string
    {
        $web = HostDir::webPath($hostDir);
        $prefix = $web === '' ? '' : $web . '/';

        return <<<HTACCESS
# Pinroll — paste into host .htaccess before front-controller (only if check returns HTML)
RewriteRule ^{$prefix}pingate\.php\$ - [L]
RewriteRule ^{$prefix}gate/ - [L]

HTACCESS;
    }

    /**
     * @param array<string, string> $files
     * @param array<string, string> $directories localDir => zipPrefix
     */
    private function createZip(string $target, array $files, array $directories = []): string
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

        foreach ($directories as $sourceDir => $zipPrefix) {
            if (!is_dir($sourceDir)) {
                continue;
            }

            $this->addDirectoryToZip($zip, $sourceDir, rtrim($zipPrefix, '/'));
        }

        $zip->close();

        return $zipPath;
    }

    private function addDirectoryToZip(ZipArchive $zip, string $sourceDir, string $zipPrefix): void
    {
        $sourceDir = rtrim($sourceDir, '/');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($sourceDir) + 1);
            $zip->addFile($file->getPathname(), $zipPrefix . '/' . str_replace('\\', '/', $relative));
        }
    }

    private function bundleGateVendor(string $gateDir): void
    {
        $sourceVendor = $this->resolveVendorSource();
        $target = $gateDir . '/vendor';
        PushProgress::arrow('Copying PinGate vendor…');
        if (is_dir($target)) {
            $this->removeDir($target);
        }

        $this->copyVendorTree($sourceVendor, $target);
        PushProgress::arrow('PinGate vendor ready');
    }

    private function resolveVendorSource(): string
    {
        $candidates = [
            $this->paths->root() . '/vendor/pinoox/pinroll/vendor',
            dirname(__DIR__, 2) . '/vendor',
            dirname($this->paths->root()) . '/pinroll/vendor',
        ];

        foreach ($candidates as $vendor) {
            if (is_file($vendor . '/autoload.php')) {
                return $vendor;
            }
        }

        throw new PinrollException(
            'Pinroll vendor not found. Run composer install in the pinroll package.',
        );
    }

    private function copyVendorTree(string $source, string $target): void
    {
        if (!is_dir($source)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $relative = $iterator->getSubPathname();
            $dest = $target . '/' . $relative;
            if ($item->isDir()) {
                if (!is_dir($dest)) {
                    mkdir($dest, 0755, true);
                }
                continue;
            }

            $parent = dirname($dest);
            if (!is_dir($parent)) {
                mkdir($parent, 0755, true);
            }

            copy($item->getPathname(), $dest);
        }
    }

    /**
     * Remove local pinroll/pingate.php, htaccess.snippet, and pinroll/gate/ after FTP upload.
     */
    public function cleanupLocalArtifacts(?string $entryPath = null, ?string $snippetPath = null, ?string $gateDir = null): void
    {
        $pinrollDir = ProjectPaths::dir($this->paths);
        $entryPath ??= $pinrollDir . '/' . HostDir::GATE_ENTRY;
        $snippetPath ??= $pinrollDir . '/htaccess.snippet';
        $gateDir ??= ProjectPaths::gateDir($this->paths);

        if (is_file($entryPath)) {
            unlink($entryPath);
        }

        if (is_file($snippetPath)) {
            unlink($snippetPath);
        }

        if (!is_dir($gateDir)) {
            return;
        }

        foreach (scandir($gateDir) ?: [] as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $gateDir . '/' . $file;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }

        @rmdir($gateDir);
    }

    /** @deprecated use cleanupLocalArtifacts() */
    private function removeLocalDeployFiles(string $entryPath, string $snippetPath, string $gateDir): void
    {
        $this->cleanupLocalArtifacts($entryPath, $snippetPath, $gateDir);
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

        rmdir($dir);
    }

    private function copyTemplate(string $template, string $destination): string
    {
        $source = self::TEMPLATE_DIR . '/' . $template;
        if (!is_file($source)) {
            throw new PinrollException('Missing PinGate template: ' . $source);
        }

        if (file_put_contents($destination, (string) file_get_contents($source)) === false) {
            throw new PinrollException('Unable to write PinGate file: ' . $destination);
        }

        return $destination;
    }
}
