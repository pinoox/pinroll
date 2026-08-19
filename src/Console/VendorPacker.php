<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Component\Package\Pinx\PlatformComposer;
use Pinoox\Pinroll\Contract\PathResolverInterface;
use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Support\ProjectPaths;
use ZipArchive;

/**
 * Export a production-ready platform vendor/ zip for the host.
 *
 * Uses the same PlatformComposer pipeline as pinx:build platform
 * (strip require-dev, materialize path-repos). Pinroll on the host is optional:
 * pingate.php can apply releases through pincore Pinx + Pinion.
 */
final class VendorPacker
{
    /**
     * @var list<string>
     */
    private const SKIP_DIR_NAMES = [
        '.git',
        'node_modules',
        '.github',
    ];

    public function __construct(
        private readonly PathResolverInterface $paths,
    ) {
    }

    /**
     * @return array{
     *     zip: string,
     *     vendor: string,
     *     files: int,
     *     bytes: int,
     *     prepared: bool,
     *     excluded_dev_packages: list<string>,
     *     materialized: list<string>
     * }
     */
    public function pack(?string $outputZip = null, bool $vendorPrune = false): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new PinrollException('ZipArchive is not available. Install the PHP zip extension.');
        }

        if (!class_exists(PlatformComposer::class)) {
            throw new PinrollException(
                'Pinoox PlatformComposer not found. Update pinoox/pincore, then retry pinroll:vendor.',
            );
        }

        $root = rtrim($this->paths->root(), '/');
        $this->assertComposerPresent($root);

        $prepared = PlatformComposer::prepare($root, true, $vendorPrune);
        if (!($prepared['prepared'] ?? false)) {
            throw new PinrollException(
                'Unable to prepare host vendor: ' . (string) ($prepared['reason'] ?? 'unknown'),
            );
        }

        $stagingVendor = PlatformComposer::vendorPath($root);
        if (!is_file($stagingVendor . '/autoload.php')) {
            PlatformComposer::cleanup($root);
            throw new PinrollException('Prepared vendor staging is missing autoload.php.');
        }

        if (!$this->pinrollPresent($stagingVendor)) {
            // Host PinGate can run on pincore (Pinx + Pinion) without pinroll in vendor.
        }

        $zipPath = $outputZip ?? ProjectPaths::vendorPackZip($this->paths);
        $dir = dirname($zipPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            PlatformComposer::cleanup($root);
            throw new PinrollException('Unable to create directory: ' . $dir);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            PlatformComposer::cleanup($root);
            throw new PinrollException('Unable to create zip: ' . $zipPath);
        }

        $files = 0;
        $bytes = 0;

        try {
            $this->addTree($zip, $stagingVendor, 'vendor', $files, $bytes);
        } finally {
            $zip->close();
            PlatformComposer::cleanup($root);
        }

        if ($files < 1) {
            @unlink($zipPath);
            throw new PinrollException('Vendor pack is empty.');
        }

        return [
            'zip' => $zipPath,
            'vendor' => $stagingVendor,
            'files' => $files,
            'bytes' => $bytes,
            'prepared' => true,
            'excluded_dev_packages' => array_values($prepared['excluded_dev_packages'] ?? []),
            'materialized' => array_values($prepared['materialized'] ?? []),
        ];
    }

    private function assertComposerPresent(string $root): void
    {
        $composerFile = $root . '/composer.json';
        if (!is_file($composerFile)) {
            throw new PinrollException('composer.json not found at project root.');
        }

        $raw = file_get_contents($composerFile);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            throw new PinrollException('Invalid composer.json.');
        }
    }

    private function pinrollPresent(string $vendorDir): bool
    {
        $pinroll = rtrim(str_replace('\\', '/', $vendorDir), '/') . '/pinoox/pinroll';
        $resolved = is_link($pinroll) ? (realpath($pinroll) ?: $pinroll) : $pinroll;

        return is_file($resolved . '/src/Pinroll.php') || is_file($resolved . '/Pinroll.php');
    }

    private function addTree(ZipArchive $zip, string $sourceDir, string $zipPrefix, int &$files, int &$bytes): void
    {
        $sourceDir = rtrim(str_replace('\\', '/', $sourceDir), '/');
        $realSource = realpath($sourceDir);
        if ($realSource === false) {
            throw new PinrollException('Cannot resolve vendor path: ' . $sourceDir);
        }
        $sourceDir = str_replace('\\', '/', $realSource);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $pathname = str_replace('\\', '/', $item->getPathname());
            $relative = substr($pathname, strlen($sourceDir) + 1);
            if ($relative === false || $relative === '') {
                continue;
            }

            if ($this->shouldSkipRelative($relative)) {
                continue;
            }

            if ($item->isLink()) {
                $target = realpath($pathname);
                if ($target === false) {
                    continue;
                }

                if (is_dir($target)) {
                    $this->addTree($zip, $target, $zipPrefix . '/' . str_replace('\\', '/', $relative), $files, $bytes);
                    continue;
                }

                if (is_file($target)) {
                    $nameInZip = $zipPrefix . '/' . str_replace('\\', '/', $relative);
                    if ($zip->addFile($target, $nameInZip)) {
                        $files++;
                        $bytes += (int) filesize($target);
                    }
                }

                continue;
            }

            if ($item->isDir() || !$item->isFile()) {
                continue;
            }

            $nameInZip = $zipPrefix . '/' . str_replace('\\', '/', $relative);
            if ($zip->addFile($pathname, $nameInZip)) {
                $files++;
                $bytes += (int) $item->getSize();
            }
        }
    }

    private function shouldSkipRelative(string $relative): bool
    {
        $parts = explode('/', str_replace('\\', '/', $relative));
        foreach ($parts as $part) {
            if (in_array($part, self::SKIP_DIR_NAMES, true)) {
                return true;
            }
        }

        return false;
    }
}
