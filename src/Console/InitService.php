<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\PinrollAutoloader;
use Pinoox\Pinroll\Support\ProjectPaths;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Non-interactive scaffold: pinroll/ config + .env key stubs.
 * Connection / PinGate upload is pinroll:connect.
 */
final class InitService
{
    public function __construct(
        private readonly string $platformRoot,
    ) {
    }

    /**
     * @return array{
     *     config: string,
     *     target: string,
     *     created: list<string>,
     *     env_keys: list<string>,
     *     env_created: list<string>
     * }
     */
    public function run(
        string $targetName,
        bool $interactive = false,
        bool $force = false,
        ?SymfonyStyle $io = null,
        bool $wizard = false,
    ): array {
        unset($interactive, $wizard);

        PinrollAutoloader::register($this->platformRoot);
        $created = (new ProjectInitializer($this->platformRoot, $force))->init();

        $paths = new NativePathResolver($this->platformRoot);
        $configFile = ProjectPaths::configFile($paths);
        Pinroll::configure([], $paths);

        // Ensure production-style target with env-backed ftp + gate
        if ($force || !is_file($configFile)) {
            ConfigWriter::write($configFile, SampleConfig::targets());
            if (!in_array($configFile, $created, true)) {
                $created[] = $configFile;
            }
        }

        $envKeys = self::envStubKeys($targetName);
        $envCreated = self::ensureEnvKeys($this->platformRoot, $envKeys);

        if ($io !== null && $envCreated !== []) {
            $io->writeln('  <fg=green>Added</> .env keys:');
            foreach ($envCreated as $key) {
                $io->writeln('    <comment>' . $key . '</comment>');
            }
        }

        return [
            'config' => $configFile,
            'target' => $targetName,
            'created' => array_values(array_filter($created)),
            'env_keys' => array_keys($envKeys),
            'env_created' => $envCreated,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function envStubKeys(string $targetName = 'production'): array
    {
        return [
            ConfigWriter::envKeyFor($targetName, 'host', 'ftp') => '',
            ConfigWriter::envKeyFor($targetName, 'user', 'ftp') => '',
            ConfigWriter::envKeyFor($targetName, 'password', 'ftp') => '',
            ConfigWriter::envKeyFor($targetName, 'url', 'pinion') => '',
            ConfigWriter::envKeyFor($targetName, 'token', 'pinion') => '',
        ];
    }

    /**
     * Create missing keys only (never overwrite existing values).
     *
     * @param array<string, string> $keys
     * @return list<string> newly created keys
     */
    public static function ensureEnvKeys(string $projectRoot, array $keys): array
    {
        $envPath = rtrim($projectRoot, '/') . '/.env';
        $missing = [];

        foreach ($keys as $key => $default) {
            if (self::envKeyExists($envPath, $key)) {
                continue;
            }
            $missing[$key] = $default;
        }

        if ($missing === []) {
            return [];
        }

        EnvFileWriter::merge($envPath, $missing);

        return array_keys($missing);
    }

    private static function envKeyExists(string $path, string $key): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return false;
        }

        $pattern = '/^' . preg_quote($key, '/') . '\s*=/';
        foreach ($lines as $line) {
            if (preg_match($pattern, (string) $line)) {
                return true;
            }
        }

        return false;
    }
}
