<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Host\HostConfig;
use Pinoox\Pinroll\Support\ConfigFileLoader;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\ProjectPaths;

/**
 * Rewrite legacy pinroll.config.php (targets → hosts, dir → deploy_path).
 */
final class ConfigMigrator
{
    /**
     * @return array{
     *     path: string,
     *     needed: bool,
     *     rendered: string,
     *     backup?: string,
     *     written: bool
     * }
     */
    public function run(string $projectRoot, bool $dryRun = false, bool $force = false): array
    {
        $paths = new NativePathResolver($projectRoot);
        $path = ProjectPaths::configFile($paths);
        if ($path === null || !is_file($path)) {
            throw new PinrollException('.pinoox/pinroll.config.php not found. Run pinroll:init first.');
        }

        /** @var array<string, mixed> $loaded */
        $loaded = ConfigFileLoader::load($path);
        $needed = isset($loaded['targets']) || $this->hostsNeedMigration(HostConfig::hostBlocks($loaded));

        $migrated = $this->migrate($loaded);
        $rendered = ConfigTemplate::renderHosts(
            $migrated['hosts'],
            $migrated['globals'],
        );

        if (!$needed && !$force) {
            return [
                'path' => $path,
                'needed' => false,
                'rendered' => $rendered,
                'written' => false,
            ];
        }

        if ($dryRun) {
            return [
                'path' => $path,
                'needed' => true,
                'rendered' => $rendered,
                'written' => false,
            ];
        }

        $backup = $path . '.bak.' . date('YmdHis');
        if (!copy($path, $backup)) {
            throw new PinrollException('Unable to create backup: ' . $backup);
        }

        if (file_put_contents($path, $rendered) === false) {
            throw new PinrollException('Unable to write migrated config.');
        }

        return [
            'path' => $path,
            'needed' => true,
            'rendered' => $rendered,
            'backup' => $backup,
            'written' => true,
        ];
    }

    /**
     * @param array<string, mixed> $loaded
     * @return array{hosts: array<string, array<string, mixed>>, globals: array<string, mixed>}
     */
    public function migrate(array $loaded): array
    {
        $loaded = HostConfig::normalizeLoaded($loaded);
        $hosts = HostConfig::hostBlocks($loaded);
        $globals = array_merge(SampleConfig::globalDefaults(), [
            'default_host' => $loaded['default_host'] ?? SampleConfig::globalDefaults()['default_host'],
            'keep' => $loaded['keep'] ?? SampleConfig::globalDefaults()['keep'],
            'store' => $loaded['store'] ?? SampleConfig::globalDefaults()['store'],
            'auto_clean' => $loaded['auto_clean'] ?? SampleConfig::globalDefaults()['auto_clean'],
        ]);

        $migratedHosts = [];
        foreach ($hosts as $name => $host) {
            if (!is_array($host)) {
                continue;
            }

            if (!isset($host['deploy_path']) && isset($host['dir'])) {
                $host['deploy_path'] = $host['dir'];
                unset($host['dir']);
            }

            if (isset($host['package']) && !isset($host['apps'])) {
                $package = $host['package'];
                if (is_string($package) && $package !== '') {
                    $host['apps'] = [$package];
                }
                unset($host['package']);
            }

            $migratedHosts[(string) $name] = $host;
        }

        if ($globals['default_host'] === 'production' && !isset($migratedHosts['production']) && $migratedHosts !== []) {
            $globals['default_host'] = (string) array_key_first($migratedHosts);
        }

        return ['hosts' => $migratedHosts, 'globals' => $globals];
    }

    /**
     * @param array<string, array<string, mixed>> $hosts
     */
    public function hostsNeedMigration(array $hosts): bool
    {
        foreach ($hosts as $host) {
            if (isset($host['dir']) || isset($host['package'])) {
                return true;
            }
        }

        return false;
    }
}
