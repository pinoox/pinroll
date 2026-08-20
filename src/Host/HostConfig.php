<?php

namespace Pinoox\Pinroll\Host;

use Pinoox\Pinroll\Support\ConfigFileLoader;

/**
 * Loads pinroll project config: hosts (or legacy targets), global defaults, per-host merge.
 */
final class HostConfig
{
    private static bool $legacyNoticeShown = false;

    /**
     * Library canonical + optional project overlay (deep merge). Later keys win.
     * Overlay hosts merge per host: setting gate.site does not wipe library via/ftp.
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $overlay
     * @return array<string, mixed>
     */
    public static function mergeDocuments(array $canonical, array $overlay): array
    {
        $canonical = self::normalizeLoaded($canonical);
        $overlay = self::normalizeLoaded($overlay);

        return self::normalizeLoaded(array_replace_recursive($canonical, $overlay));
    }

    /**
     * @return array<string, mixed>
     */
    public static function loadMerged(?string $overlayPath = null): array
    {
        $canonical = [];
        $library = ConfigFileLoader::libraryPath();
        if (is_file($library)) {
            $canonical = ConfigFileLoader::load($library);
        }

        $overlay = [];
        if ($overlayPath !== null && $overlayPath !== '' && is_file($overlayPath)) {
            $overlay = ConfigFileLoader::load($overlayPath);
        }

        return self::mergeDocuments($canonical, $overlay);
    }

    /**
     * @param array<string, mixed> $loaded
     * @return array<string, mixed>
     */
    public static function normalizeLoaded(array $loaded): array
    {
        if (!isset($loaded['hosts']) && isset($loaded['targets']) && is_array($loaded['targets'])) {
            if (!self::$legacyNoticeShown) {
                self::$legacyNoticeShown = true;
                if (function_exists('error_log')) {
                    error_log('[pinroll] Deprecated: pinroll.config.php uses "targets" — rename to "hosts".');
                }
            }
            $loaded['hosts'] = $loaded['targets'];
        }

        return $loaded;
    }

    /**
     * @param array<string, mixed> $loaded Full project config
     * @return array<string, array<string, mixed>>
     */
    public static function hostBlocks(array $loaded): array
    {
        $loaded = self::normalizeLoaded($loaded);
        $hosts = $loaded['hosts'] ?? [];

        return is_array($hosts) ? $hosts : [];
    }

    /**
     * @param array<string, mixed> $loaded
     * @return array<string, mixed> Keys to merge into Pinroll engine Config (excludes hosts)
     */
    public static function engineOverrides(array $loaded): array
    {
        $loaded = self::normalizeLoaded($loaded);
        $overrides = $loaded;
        unset($overrides['hosts'], $overrides['targets']);

        return $overrides;
    }

    /**
     * Merge global retention + other inherited keys into a raw host block.
     *
     * @param array<string, mixed> $loaded
     * @param array<string, mixed> $host
     * @return array<string, mixed>
     */
    public static function mergeHostDefaults(array $loaded, array $host): array
    {
        $loaded = self::normalizeLoaded($loaded);

        foreach (['keep', 'store', 'auto_clean', 'clean_before_deploy', 'stale_days', 'lang'] as $key) {
            if (!array_key_exists($key, $host) && array_key_exists($key, $loaded)) {
                $host[$key] = $loaded[$key];
            }
        }

        if (!isset($host['provision']) && isset($loaded['provision']) && is_array($loaded['provision'])) {
            $host['provision'] = $loaded['provision'];
        } elseif (isset($host['provision'], $loaded['provision']) && is_array($host['provision']) && is_array($loaded['provision'])) {
            $host['provision'] = array_replace_recursive($loaded['provision'], $host['provision']);
        }

        if (!isset($host['deploy_path']) && isset($host['dir'])) {
            $host['deploy_path'] = $host['dir'];
        }

        if (!isset($host['dir']) && isset($host['deploy_path'])) {
            $host['dir'] = $host['deploy_path'];
        }

        return $host;
    }

    public static function defaultHostName(array $loaded): ?string
    {
        $loaded = self::normalizeLoaded($loaded);
        $default = trim((string) ($loaded['default_host'] ?? ''));

        if ($default !== '') {
            return $default;
        }

        $names = array_keys(self::hostBlocks($loaded));

        return count($names) === 1 ? (string) $names[0] : null;
    }
}
