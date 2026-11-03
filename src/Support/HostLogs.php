<?php

namespace Pinoox\Pinroll\Support;

use Pinoox\Pinroll\Exception\PinrollException;

final class HostLogs
{
    public const TYPE_ALL = 'all';
    public const TYPE_APP = 'app';
    public const TYPE_SERVER = 'server';
    public const TYPE_PINROLL = 'pinroll';

    /**
     * Handle incoming options: list log files or read specific log file.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function handle(string $root, array $options = []): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');

        if (!empty($options['latest'])) {
            $list = self::list($root, $options);
            $files = $list['files'] ?? [];
            if ($files === []) {
                return [
                    'host' => (string) ($options['host'] ?? 'local'),
                    'total' => 0,
                    'files' => [],
                    'message' => 'No log files found.',
                ];
            }

            $options['file'] = $files[0]['path'];

            return self::read($root, (string) $options['file'], $options);
        }

        if (!empty($options['file'])) {
            return self::read($root, (string) $options['file'], $options);
        }

        return self::list($root, $options);
    }

    /**
     * List all discovered log files across storage/logs, server roots, and pinroll.
     *
     * @param array<string, mixed> $options
     * @return array{
     *     host: string,
     *     total: int,
     *     files: list<array{
     *         name: string,
     *         path: string,
     *         type: string,
     *         size: int,
     *         size_formatted: string,
     *         modified_at: int,
     *         modified_formatted: string,
     *         lines_count: int|null
     *     }>
     * }
     */
    public static function list(string $root, array $options = []): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $typeFilter = (string) ($options['type'] ?? self::TYPE_ALL);
        $searchFilter = strtolower(trim((string) ($options['search'] ?? '')));
        $limit = isset($options['limit']) ? max(1, (int) $options['limit']) : 100;

        $files = [];

        // 1. App logs: storage/logs/
        if ($typeFilter === self::TYPE_ALL || $typeFilter === self::TYPE_APP) {
            $logsDir = $root . '/storage/logs';
            if (is_dir($logsDir)) {
                $files = array_merge($files, self::scanDirectory($logsDir, $root, self::TYPE_APP));
            }
        }

        // 2. Server / PHP error logs: root and public_html
        if ($typeFilter === self::TYPE_ALL || $typeFilter === self::TYPE_SERVER) {
            $serverCandidates = [
                $root . '/error_log',
                $root . '/php_error.log',
                $root . '/php_errors.log',
                $root . '/public_html/error_log',
                $root . '/public_html/php_error.log',
            ];

            foreach ($serverCandidates as $serverFile) {
                if (is_file($serverFile)) {
                    $files[] = self::fileInfo($serverFile, $root, self::TYPE_SERVER);
                }
            }
        }

        // 3. Pinroll logs: storage/pinroll/gate/*.log and storage/pinroll/*.log
        if ($typeFilter === self::TYPE_ALL || $typeFilter === self::TYPE_PINROLL) {
            $gateDir = $root . '/storage/pinroll/gate';
            if (is_dir($gateDir)) {
                $files = array_merge($files, self::scanDirectory($gateDir, $root, self::TYPE_PINROLL));
            }

            $pinrollDir = $root . '/storage/pinroll';
            if (is_dir($pinrollDir)) {
                $entries = @scandir($pinrollDir) ?: [];
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..' || !str_ends_with($entry, '.log')) {
                        continue;
                    }
                    $fullPath = $pinrollDir . '/' . $entry;
                    if (is_file($fullPath)) {
                        $files[] = self::fileInfo($fullPath, $root, self::TYPE_PINROLL);
                    }
                }
            }
        }

        // Deduplicate files by relative path
        $unique = [];
        foreach ($files as $file) {
            $unique[$file['path']] = $file;
        }
        $files = array_values($unique);

        // Filter by filename search query if specified
        if ($searchFilter !== '') {
            $files = array_values(array_filter($files, static function (array $item) use ($searchFilter): bool {
                return str_contains(strtolower($item['name']), $searchFilter)
                    || str_contains(strtolower($item['path']), $searchFilter);
            }));
        }

        // Sort descending by modified_at (newest first)
        usort($files, static fn (array $a, array $b): int => $b['modified_at'] <=> $a['modified_at']);

        if (count($files) > $limit) {
            $files = array_slice($files, 0, $limit);
        }

        return [
            'host' => (string) ($options['host'] ?? 'local'),
            'total' => count($files),
            'files' => $files,
        ];
    }

    /**
     * Read content / tail lines from a specified log file.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function read(string $root, string $file, array $options = []): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $resolvedPath = self::resolveSafePath($root, $file);

        $size = filesize($resolvedPath) ?: 0;
        $mtime = filemtime($resolvedPath) ?: 0;
        $maxLines = isset($options['lines']) ? max(1, min(10000, (int) $options['lines'])) : 100;
        $search = trim((string) ($options['search'] ?? ''));
        $level = strtoupper(trim((string) ($options['level'] ?? '')));

        $lines = self::tailFile($resolvedPath, $maxLines, $search, $level);
        $totalLines = self::countLines($resolvedPath);

        $type = self::detectType($resolvedPath, $root);
        $relPath = self::relativePath($resolvedPath, $root);

        return [
            'host' => (string) ($options['host'] ?? 'local'),
            'file' => basename($resolvedPath),
            'path' => $relPath,
            'type' => $type,
            'size' => $size,
            'size_formatted' => self::formatBytes($size),
            'modified_at' => $mtime,
            'modified_formatted' => date('Y-m-d H:i:s', $mtime),
            'total_lines' => $totalLines,
            'lines_returned' => count($lines),
            'lines' => $lines,
            'content' => implode("\n", $lines),
        ];
    }

    /**
     * Resolve and validate that a requested file is safe and within allowed log directories.
     */
    public static function resolveSafePath(string $root, string $file): string
    {
        $rootReal = realpath($root);
        if ($rootReal === false || !is_dir($rootReal)) {
            throw new PinrollException('Invalid platform root directory.', 400);
        }
        $rootNorm = rtrim(str_replace('\\', '/', $rootReal), '/');

        // Clean user input
        $file = str_replace('\\', '/', trim($file));
        if ($file === '' || str_contains($file, "\0") || str_contains($file, '..')) {
            throw new PinrollException('Invalid log file name: ' . $file, 400);
        }

        // Candidate paths to search
        $candidates = [
            $rootNorm . '/' . ltrim($file, '/'),
            $rootNorm . '/storage/logs/' . ltrim($file, '/'),
            $rootNorm . '/storage/pinroll/gate/' . ltrim($file, '/'),
            $rootNorm . '/storage/pinroll/' . ltrim($file, '/'),
        ];

        $resolved = null;
        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            if ($real !== false && is_file($real)) {
                $resolved = str_replace('\\', '/', $real);
                break;
            }
        }

        if ($resolved === null) {
            throw new PinrollException('Log file not found: ' . $file, 404);
        }

        // Security check: Must reside within storage/logs, storage/pinroll, or be an allowed server log in root
        $allowedPrefixes = [
            $rootNorm . '/storage/logs/',
            $rootNorm . '/storage/pinroll/',
        ];

        $isAllowed = false;
        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($resolved, $prefix)) {
                $isAllowed = true;
                break;
            }
        }

        if (!$isAllowed) {
            $serverAllowed = [
                $rootNorm . '/error_log',
                $rootNorm . '/php_error.log',
                $rootNorm . '/php_errors.log',
                $rootNorm . '/public_html/error_log',
                $rootNorm . '/public_html/php_error.log',
            ];

            if (in_array($resolved, $serverAllowed, true)) {
                $isAllowed = true;
            }
        }

        if (!$isAllowed) {
            throw new PinrollException('Access denied to file: ' . $file, 403);
        }

        return $resolved;
    }

    /**
     * @return list<array{
     *     name: string,
     *     path: string,
     *     type: string,
     *     size: int,
     *     size_formatted: string,
     *     modified_at: int,
     *     modified_formatted: string,
     *     lines_count: int|null
     * }>
     */
    private static function scanDirectory(string $dir, string $root, string $type): array
    {
        $items = [];
        $entries = @scandir($dir) ?: [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $dir . '/' . $entry;
            if (is_dir($fullPath)) {
                // Subdirectory scan (1 level)
                $subEntries = @scandir($fullPath) ?: [];
                foreach ($subEntries as $sub) {
                    if ($sub === '.' || $sub === '..') {
                        continue;
                    }
                    $subPath = $fullPath . '/' . $sub;
                    if (is_file($subPath) && self::isLogFile($sub)) {
                        $items[] = self::fileInfo($subPath, $root, $type);
                    }
                }
                continue;
            }

            if (is_file($fullPath) && self::isLogFile($entry)) {
                $items[] = self::fileInfo($fullPath, $root, $type);
            }
        }

        return $items;
    }

    private static function isLogFile(string $filename): bool
    {
        $lower = strtolower($filename);

        return str_ends_with($lower, '.log')
            || str_ends_with($lower, '.txt')
            || str_starts_with($lower, 'error_log')
            || str_starts_with($lower, 'php_error');
    }

    /**
     * @return array{
     *     name: string,
     *     path: string,
     *     type: string,
     *     size: int,
     *     size_formatted: string,
     *     modified_at: int,
     *     modified_formatted: string,
     *     lines_count: int|null
     * }
     */
    private static function fileInfo(string $fullPath, string $root, string $type): array
    {
        $size = filesize($fullPath) ?: 0;
        $mtime = filemtime($fullPath) ?: 0;

        // Count lines for files under 5MB
        $linesCount = $size <= 5 * 1024 * 1024 ? self::countLines($fullPath) : null;

        return [
            'name' => basename($fullPath),
            'path' => self::relativePath($fullPath, $root),
            'type' => $type,
            'size' => $size,
            'size_formatted' => self::formatBytes($size),
            'modified_at' => $mtime,
            'modified_formatted' => date('Y-m-d H:i:s', $mtime),
            'lines_count' => $linesCount,
        ];
    }

    /**
     * Tail the last N lines of a file efficiently using reverse buffer seeking.
     *
     * @return list<string>
     */
    public static function tailFile(string $filePath, int $linesNeeded, string $search = '', string $level = ''): array
    {
        $size = filesize($filePath) ?: 0;
        if ($size === 0) {
            return [];
        }

        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            return [];
        }

        $allLines = [];

        // For files <= 2MB, read directly
        if ($size <= 2 * 1024 * 1024) {
            $content = stream_get_contents($handle);
            fclose($handle);
            if ($content === false || $content === '') {
                return [];
            }
            $allLines = explode("\n", str_replace("\r\n", "\n", $content));
        } else {
            // Reverse buffer read
            fseek($handle, 0, SEEK_END);
            $pos = ftell($handle);
            $chunkSize = 8192;
            $buffer = '';
            $linesCount = 0;
            // Read until we have enough lines (or 3x linesNeeded if filters applied)
            $targetCount = ($search !== '' || $level !== '') ? $linesNeeded * 5 : $linesNeeded;

            while ($pos > 0 && $linesCount <= $targetCount) {
                $readSize = min($chunkSize, $pos);
                $pos -= $readSize;
                fseek($handle, $pos, SEEK_SET);
                $read = fread($handle, $readSize);
                if ($read === false) {
                    break;
                }
                $buffer = $read . $buffer;
                $linesCount = substr_count($buffer, "\n");
            }
            fclose($handle);

            $allLines = explode("\n", str_replace("\r\n", "\n", $buffer));
        }

        // Clean trailing empty lines
        while (!empty($allLines) && end($allLines) === '') {
            array_pop($allLines);
        }

        // Apply search and level filters
        if ($search !== '' || $level !== '') {
            $allLines = array_values(array_filter($allLines, static function (string $line) use ($search, $level): bool {
                if ($search !== '' && !str_contains(strtolower($line), strtolower($search))) {
                    return false;
                }
                if ($level !== '') {
                    $upper = strtoupper($line);
                    $hasLevel = str_contains($upper, '.' . $level . ':')
                        || str_contains($upper, '[' . $level . ']')
                        || str_contains($upper, $level . ':');
                    if (!$hasLevel) {
                        return false;
                    }
                }

                return true;
            }));
        }

        if (count($allLines) > $linesNeeded) {
            $allLines = array_slice($allLines, -$linesNeeded);
        }

        return array_values($allLines);
    }

    public static function countLines(string $filePath): int
    {
        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            return 0;
        }

        $lines = 0;
        while (!feof($handle)) {
            $chunk = fread($handle, 65536);
            if ($chunk === false) {
                break;
            }
            $lines += substr_count($chunk, "\n");
        }
        fclose($handle);

        return $lines;
    }

    public static function relativePath(string $fullPath, string $root): string
    {
        $normFull = str_replace('\\', '/', $fullPath);
        $normRoot = rtrim(str_replace('\\', '/', $root), '/');

        if (str_starts_with($normFull, $normRoot . '/')) {
            return substr($normFull, strlen($normRoot) + 1);
        }

        return $normFull;
    }

    private static function detectType(string $fullPath, string $root): string
    {
        $rel = self::relativePath($fullPath, $root);
        if (str_starts_with($rel, 'storage/logs/')) {
            return self::TYPE_APP;
        }
        if (str_starts_with($rel, 'storage/pinroll/')) {
            return self::TYPE_PINROLL;
        }

        return self::TYPE_SERVER;
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MB';
        }

        return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
    }
}
