<?php

namespace Pinoox\Pinroll\Host;

use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Release\ReleaseManifest;
use Pinoox\Pinroll\Support\IncomingRelease;

/**
 * Keeps a local copy of pushed archives when store=local|both.
 */
final class LocalArchiveStore
{
    /**
     * @param array<string, mixed> $host Resolved or raw host (must include store when set)
     */
    public static function shouldKeep(array $host): bool
    {
        $store = RetentionPolicy::settings($host)['store'];

        return $store === 'local' || $store === 'both';
    }

    /**
     * Copy the installable archive into local storage/pinroll/incoming for rollback.
     */
    public static function keep(string $archivePath, ReleaseManifest $manifest, array $host): ?string
    {
        if (!self::shouldKeep($host) || !is_file($archivePath)) {
            return null;
        }

        $incoming = Pinroll::config()->storage((string) Pinroll::config()->get('incoming_path', 'pinroll/incoming'));
        if (!is_dir($incoming) && !mkdir($incoming, 0755, true) && !is_dir($incoming)) {
            return null;
        }

        $deployId = $manifest->deployId();
        if ($deployId === '') {
            $deployId = IncomingRelease::idFromArchive($archivePath);
        }

        $source = $archivePath;
        $lower = strtolower($archivePath);
        if (str_ends_with($lower, '.tar')) {
            $workDir = Pinroll::config()->storage('tmp/local-store/' . $deployId);
            try {
                $source = IncomingRelease::resolveInstallable($archivePath, $workDir);
            } catch (\Throwable) {
                $source = $archivePath;
            }
        }

        $ext = pathinfo($source, PATHINFO_EXTENSION) ?: 'pinx';
        $dest = $incoming . '/' . $deployId . '.' . $ext;

        if (realpath($source) === realpath($dest)) {
            return $dest;
        }

        if (!@copy($source, $dest)) {
            return null;
        }

        return $dest;
    }
}
