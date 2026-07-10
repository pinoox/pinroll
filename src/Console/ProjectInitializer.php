<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\ProjectPaths;

final class ProjectInitializer
{
    public function __construct(
        private readonly ?string $projectRoot = null,
        private readonly bool $force = false,
    ) {
    }

    /**
     * @return list<string>
     */
    public function init(): array
    {
        $written = [];
        $paths = new NativePathResolver($this->projectRoot);
        $configFile = ProjectPaths::configFile($paths);

        if (!is_file($configFile) || $this->force) {
            ConfigWriter::write($configFile, SampleConfig::targets(ProjectPackages::defaultPackage($this->projectRoot)));
            $written[] = $configFile;
        }

        return array_values(array_filter($written));
    }

    /**
     * Optional custom bundle recipes (advanced). Not required for normal app deploy.
     *
     * @return list<string>
     */
    public function initBundles(): array
    {
        return [];
    }
}
