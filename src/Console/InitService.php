<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\PinrollAutoloader;
use Pinoox\Pinroll\Support\ProjectPaths;
use Symfony\Component\Console\Style\SymfonyStyle;

final class InitService
{
    public function __construct(
        private readonly string $platformRoot,
    ) {
    }

    /**
     * @return array{config: string, target: string, gate_configured?: bool, ready_for_push?: bool}
     */
    public function run(
        string $targetName,
        bool $interactive,
        bool $force,
        ?SymfonyStyle $io = null,
        bool $wizard = false,
    ): array {
        PinrollAutoloader::register($this->platformRoot);
        (new ProjectInitializer($this->platformRoot, $force))->init();

        $paths = new NativePathResolver($this->platformRoot);
        $configFile = ProjectPaths::configFile($paths);

        Pinroll::configure([], $paths);
        $needsSetup = $force || !is_file($configFile);

        if (!$needsSetup) {
            try {
                Pinroll::targets()->resolve($targetName);
            } catch (\Throwable) {
                $needsSetup = true;
            }
        }

        if ($needsSetup && $interactive && $io !== null) {
            $targets = ConnectionSetup::collect($io, $targetName);
            ConfigWriter::write($configFile, $targets);
        } elseif ($needsSetup) {
            ConfigWriter::write($configFile, SampleConfig::targets(ProjectPackages::defaultPackage($this->platformRoot)));
        }

        $result = [
            'config' => $configFile,
            'target' => $targetName,
        ];

        if ($wizard && $interactive && $io !== null) {
            $gate = GateSetupWizard::run($io, $this->platformRoot, $targetName, $configFile);
            $result = array_merge($result, $gate);
        }

        return $result;
    }
}
