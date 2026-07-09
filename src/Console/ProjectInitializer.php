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
        $written = $this->initBundles();
        $paths = new NativePathResolver($this->projectRoot);
        $configFile = ProjectPaths::configFile($paths);

        if (!is_file($configFile) || $this->force) {
            ConfigWriter::write($configFile, SampleConfig::targets(ProjectPackages::defaultPackage($this->projectRoot)));
            $written[] = $configFile;
        }

        return array_values(array_filter($written));
    }

    /**
     * @return list<string>
     */
    public function initBundles(): array
    {
        $paths = new NativePathResolver($this->projectRoot);
        $stubs = dirname(__DIR__, 2) . '/stubs';
        $written = [];
        $bundlesDir = ProjectPaths::bundlesDir($paths);

        if (!is_dir($bundlesDir) && !mkdir($bundlesDir, 0755, true) && !is_dir($bundlesDir)) {
            throw new PinrollException('Unable to create pinroll bundles directory: ' . $bundlesDir);
        }

        foreach (['single-app', 'platform-core', 'platform-full', 'test-empty'] as $bundle) {
            $written[] = $this->copyStub(
                $stubs . '/bundles/' . $bundle . '.php.stub',
                ProjectPaths::bundleFile($paths, $bundle),
            );
        }

        return array_values(array_filter($written));
    }

    private function copyStub(string $stub, string $destination): ?string
    {
        if (!is_file($stub)) {
            throw new PinrollException('Missing pinroll stub: ' . $stub);
        }

        if (is_file($destination) && !$this->force) {
            return null;
        }

        $contents = (string) file_get_contents($stub);

        if (file_put_contents($destination, $contents) === false) {
            throw new PinrollException('Unable to write: ' . $destination);
        }

        return $destination;
    }
}
