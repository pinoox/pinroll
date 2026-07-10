<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\ProjectPaths;

final class HostAppsWriter
{
    public function __construct(
        private readonly string $projectRoot,
    ) {
    }

    /**
     * @return list<string>
     */
    public function read(string $hostName): array
    {
        $raw = Pinroll::hosts()->raw($hostName);
        $apps = $raw['apps'] ?? null;

        if (!is_array($apps)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $apps)));
    }

    /**
     * @param list<string>|null $apps null clears apps from config
     */
    public function write(string $hostName, ?array $apps): void
    {
        $path = ProjectPaths::configFile(Pinroll::paths());
        if ($path === null || !is_file($path)) {
            throw new PinrollException('pinroll/pinroll.config.php not found. Run pinroll:init first.');
        }

        if ($apps !== null && $apps !== []) {
            $unknown = $this->unknownPackages($apps);
            if ($unknown !== []) {
                throw new PinrollException(
                    'Unknown app package(s): ' . implode(', ', $unknown)
                    . '. Install the app under apps/ or check the package name.',
                );
            }
        }

        ConfigWriter::setHostApps($path, $hostName, $apps);
    }

    /**
     * @param list<string> $apps
     * @return list<string>
     */
    public function unknownPackages(array $apps): array
    {
        $available = ProjectPackages::list($this->projectRoot);

        return array_values(array_diff($apps, $available));
    }

    /**
     * @return list<string>
     */
    public static function parseList(string $value): array
    {
        $parts = preg_split('/[,\s]+/', $value) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $parts))));
    }
}
