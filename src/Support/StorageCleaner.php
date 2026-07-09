<?php

namespace Pinoox\Pinroll\Support;

/**
 * Prune Pinroll storage on a host (incoming archives, tmp, staging, old sessions/releases).
 */
final class StorageCleaner
{
    public function __construct(
        private readonly Config $config,
    ) {
    }

    /**
     * @param array{
     *     keep?: int,
     *     dry_run?: bool,
     *     incoming?: bool,
     *     tmp?: bool,
     *     staging?: bool,
     *     sessions?: bool,
     *     releases?: bool,
     *     backups?: bool
     * } $options
     * @return array{
     *     dry_run: bool,
     *     keep: int,
     *     deleted: list<array{path: string, bytes: int, reason: string}>,
     *     kept: list<string>,
     *     bytes_freed: int,
     *     files_deleted: int
     * }
     */
    public function clean(array $options = []): array
    {
        $keep = max(0, (int) ($options['keep'] ?? 3));
        $dryRun = !empty($options['dry_run']);
        $scopes = [
            'incoming' => $options['incoming'] ?? true,
            'tmp' => $options['tmp'] ?? true,
            'staging' => $options['staging'] ?? true,
            'sessions' => $options['sessions'] ?? true,
            'releases' => $options['releases'] ?? true,
            'backups' => $options['backups'] ?? true,
        ];

        $deleted = [];
        $kept = [];

        if ($scopes['incoming']) {
            $this->cleanIncoming($keep, $dryRun, $deleted, $kept);
        }

        if ($scopes['tmp']) {
            $this->cleanTree($this->config->storage('tmp'), $dryRun, $deleted, 'tmp');
        }

        if ($scopes['staging']) {
            $this->cleanTree($this->config->storage('staging'), $dryRun, $deleted, 'staging');
        }

        if ($scopes['sessions']) {
            $this->cleanOldFiles($this->config->storage('sessions'), $keep, $dryRun, $deleted, $kept, 'sessions');
        }

        if ($scopes['releases']) {
            $this->cleanReleaseDirs($keep, $dryRun, $deleted, $kept);
        }

        if ($scopes['backups']) {
            $this->cleanReleaseLikeDirs($this->config->storage('backups'), $keep, $dryRun, $deleted, $kept, 'backups');
        }

        $bytes = array_sum(array_column($deleted, 'bytes'));

        return [
            'dry_run' => $dryRun,
            'keep' => $keep,
            'deleted' => $deleted,
            'kept' => $kept,
            'bytes_freed' => $bytes,
            'files_deleted' => count($deleted),
        ];
    }

    /**
     * @param list<array{path: string, bytes: int, reason: string}> $deleted
     * @param list<string> $kept
     */
    private function cleanIncoming(int $keep, bool $dryRun, array &$deleted, array &$kept): void
    {
        $incoming = $this->config->storage((string) $this->config->get('incoming_path', 'pinroll/incoming'));
        $releases = IncomingRelease::list($incoming);

        foreach ($releases as $index => $release) {
            $path = $release['path'];
            $label = basename($path);

            if ($index < $keep) {
                $kept[] = 'incoming/' . $label;

                continue;
            }

            $bytes = (int) ($release['size'] ?? 0);
            if (!$dryRun) {
                @unlink($path);
            }

            $deleted[] = [
                'path' => 'incoming/' . $label,
                'bytes' => $bytes,
                'reason' => 'older than keep=' . $keep,
            ];
        }
    }

    /**
     * @param list<array{path: string, bytes: int, reason: string}> $deleted
     * @param list<string> $kept
     */
    private function cleanReleaseDirs(int $keep, bool $dryRun, array &$deleted, array &$kept): void
    {
        $releasesRoot = $this->config->storage('releases');
        $current = $releasesRoot . '/current';
        $currentTarget = is_link($current) ? (string) readlink($current) : '';

        $this->cleanReleaseLikeDirs($releasesRoot, $keep, $dryRun, $deleted, $kept, 'releases', [
            'current',
            $currentTarget !== '' ? basename($currentTarget) : '',
        ]);
    }

    /**
     * @param list<string> $alwaysKeep
     * @param list<array{path: string, bytes: int, reason: string}> $deleted
     * @param list<string> $kept
     */
    private function cleanReleaseLikeDirs(
        string $root,
        int $keep,
        bool $dryRun,
        array &$deleted,
        array &$kept,
        string $label,
        array $alwaysKeep = [],
    ): void {
        if (!is_dir($root)) {
            return;
        }

        $dirs = [];
        foreach (scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..' || $name === 'current') {
                continue;
            }

            $path = $root . '/' . $name;
            if (!is_dir($path)) {
                continue;
            }

            $dirs[] = [
                'name' => $name,
                'path' => $path,
                'mtime' => (int) filemtime($path),
                'bytes' => $this->dirBytes($path),
            ];
        }

        usort($dirs, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

        $alwaysKeep = array_values(array_filter($alwaysKeep));
        $keptCount = 0;

        foreach ($dirs as $dir) {
            $name = $dir['name'];
            $mustKeep = in_array($name, $alwaysKeep, true) || $keptCount < $keep;

            if ($mustKeep) {
                $kept[] = $label . '/' . $name;
                if (!in_array($name, $alwaysKeep, true)) {
                    $keptCount++;
                }

                continue;
            }

            if (!$dryRun) {
                $this->removeTree($dir['path']);
            }

            $deleted[] = [
                'path' => $label . '/' . $name,
                'bytes' => $dir['bytes'],
                'reason' => 'older than keep=' . $keep,
            ];
        }
    }

    /**
     * @param list<array{path: string, bytes: int, reason: string}> $deleted
     * @param list<string> $kept
     */
    private function cleanOldFiles(
        string $dir,
        int $keep,
        bool $dryRun,
        array &$deleted,
        array &$kept,
        string $label,
    ): void {
        if (!is_dir($dir)) {
            return;
        }

        $files = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $dir . '/' . $name;
            if (!is_file($path)) {
                continue;
            }

            $files[] = [
                'name' => $name,
                'path' => $path,
                'mtime' => (int) filemtime($path),
                'bytes' => (int) filesize($path),
            ];
        }

        usort($files, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

        foreach ($files as $index => $file) {
            if ($index < $keep) {
                $kept[] = $label . '/' . $file['name'];

                continue;
            }

            if (!$dryRun) {
                @unlink($file['path']);
            }

            $deleted[] = [
                'path' => $label . '/' . $file['name'],
                'bytes' => $file['bytes'],
                'reason' => 'older than keep=' . $keep,
            ];
        }
    }

    /**
     * @param list<array{path: string, bytes: int, reason: string}> $deleted
     */
    private function cleanTree(string $dir, bool $dryRun, array &$deleted, string $label): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $bytes = $this->dirBytes($dir);
        if ($bytes === 0 && count(scandir($dir) ?: []) <= 2) {
            return;
        }

        if (!$dryRun) {
            $this->removeTreeContents($dir);
        }

        $deleted[] = [
            'path' => $label . '/',
            'bytes' => $bytes,
            'reason' => 'temporary workspace',
        ];
    }

    private function dirBytes(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $total = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $total += (int) $file->getSize();
            }
        }

        return $total;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $this->removeTreeContents($dir);
        @rmdir($dir);
    }

    private function removeTreeContents(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            $path = $file->getPathname();
            $file->isDir() ? @rmdir($path) : @unlink($path);
        }
    }
}
