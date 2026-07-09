<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Contract\PathResolverInterface;
use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Support\ProjectPaths;
use ZipArchive;

/**
 * Export platform vendor/ for host install or core/dependency updates.
 * Follows Composer path-repository symlinks into real files inside the zip.
 */
final class VendorPacker
{
    /**
     * Skip only VCS / junk folders — never skip packages (phpunit etc. are required by composer autoload_files).
     *
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
     * @return array{zip: string, vendor: string, files: int, bytes: int}
     */
    public function pack(?string $outputZip = null): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new PinrollException('ZipArchive is not available. Install the PHP zip extension.');
        }

        $vendor = rtrim($this->paths->root(), '/') . '/vendor';
        if (!is_file($vendor . '/autoload.php')) {
            throw new PinrollException('Platform vendor not found. Run composer install first: ' . $vendor);
        }

        if (!is_file($vendor . '/pinoox/pinroll/src/Pinroll.php') && !is_file($vendor . '/pinoox/pinroll/Pinroll.php')) {
            // path repo: symlink to package root with src/Pinroll.php
            $pinroll = $vendor . '/pinoox/pinroll';
            $resolved = is_link($pinroll) ? (realpath($pinroll) ?: $pinroll) : $pinroll;
            if (!is_file($resolved . '/src/Pinroll.php')) {
                throw new PinrollException(
                    'pinoox/pinroll is missing from vendor. Add "pinoox/pinroll" to composer.json and run composer update.',
                );
            }
        }

        $zipPath = $outputZip ?? ProjectPaths::vendorPackZip($this->paths);
        $dir = dirname($zipPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new PinrollException('Unable to create directory: ' . $dir);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new PinrollException('Unable to create zip: ' . $zipPath);
        }

        $files = 0;
        $bytes = 0;
        $this->addTree($zip, $vendor, 'vendor', $files, $bytes);
        $zip->close();

        if ($files < 1) {
            @unlink($zipPath);
            throw new PinrollException('Vendor pack is empty.');
        }

        return [
            'zip' => $zipPath,
            'vendor' => $vendor,
            'files' => $files,
            'bytes' => $bytes,
        ];
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

            // Follow symlinks to real files (Composer path repositories)
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

            if ($item->isDir()) {
                continue;
            }

            if (!$item->isFile()) {
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
