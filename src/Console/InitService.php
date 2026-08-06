<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\ConfigFileLoader;
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
     *     host: string,
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

        $targetName = self::normalizeHostName($targetName);

        PinrollAutoloader::register($this->platformRoot);
        $created = (new ProjectInitializer($this->platformRoot, $force, $targetName))->init();

        $paths = new NativePathResolver($this->platformRoot);
        $configFile = ProjectPaths::configFile($paths);
        Pinroll::configure([], $paths);

        // Config already existed: make sure this host is present with matching env keys.
        if (is_file($configFile) && !$force) {
            if (self::ensureHostInConfig($configFile, $targetName) && !in_array($configFile, $created, true)) {
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
            'host' => $targetName,
            'created' => array_values(array_filter($created)),
            'env_keys' => array_keys($envKeys),
            'env_created' => $envCreated,
        ];
    }

    public static function normalizeHostName(string $name): string
    {
        $name = strtolower(trim($name));
        if ($name === '') {
            $name = 'production';
        }

        if (!preg_match('/^[a-z][a-z0-9_-]*$/', $name)) {
            throw new PinrollException(
                'Invalid host name "' . $name . '". Use lowercase letters, numbers, hyphens or underscores (e.g. myconnect).',
            );
        }

        return $name;
    }

    /**
     * @return array<string, string>
     */
    public static function envStubKeys(string $targetName = 'production'): array
    {
        $targetName = self::normalizeHostName($targetName);

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

    /**
     * Add host block when missing. Returns true if config was updated.
     */
    private static function ensureHostInConfig(string $configFile, string $hostName): bool
    {
        $loaded = ConfigFileLoader::load($configFile);
        /** @var array<string, array<string, mixed>> $hosts */
        $hosts = is_array($loaded['hosts'] ?? null) ? $loaded['hosts'] : [];
        if ($hosts === [] && is_array($loaded['targets'] ?? null)) {
            $hosts = $loaded['targets'];
        }

        if (isset($hosts[$hostName]) && is_array($hosts[$hostName])) {
            return false;
        }

        $hosts[$hostName] = SampleConfig::productionHost($hostName);

        $globals = [
            'default_host' => (string) ($loaded['default_host'] ?? $hostName),
            'keep' => (int) ($loaded['keep'] ?? 3),
            'store' => (string) ($loaded['store'] ?? 'remote'),
            'auto_clean' => (bool) ($loaded['auto_clean'] ?? true),
        ];

        foreach ($hosts as $name => $host) {
            if (!is_string($name) || !is_array($host)) {
                continue;
            }
            // Re-wrap evaluated plain strings into env-backed fields for known keys.
            $hosts[$name] = self::normalizeLoadedHost((string) $name, $host);
        }

        ConfigWriter::writeHosts($configFile, $hosts, $globals);

        return true;
    }

    /**
     * @param array<string, mixed> $host
     * @return array<string, mixed>
     */
    private static function normalizeLoadedHost(string $name, array $host): array
    {
        $via = (string) ($host['via'] ?? 'ftp');
        $normalized = [
            'deploy_path' => (string) ($host['deploy_path'] ?? $host['dir'] ?? 'public_html'),
            'via' => $via !== '' ? $via : 'ftp',
        ];

        if (array_key_exists('web_path', $host)) {
            $normalized['web_path'] = (string) $host['web_path'];
        }

        if (!empty($host['apps']) && is_array($host['apps'])) {
            $normalized['apps'] = array_values(array_filter(array_map('strval', $host['apps'])));
        }

        $gate = is_array($host['gate'] ?? null) ? $host['gate'] : null;
        if ($gate !== null) {
            $normalized['gate'] = [
                'url' => [
                    '_env' => ConfigWriter::envKeyFor($name, 'url', 'pinion'),
                    'default' => (string) ($gate['url'] ?? ''),
                ],
                'token' => [
                    '_env' => ConfigWriter::envKeyFor($name, 'token', 'pinion'),
                    'default' => (string) ($gate['token'] ?? ''),
                ],
            ];
        } else {
            $normalized['gate'] = SampleConfig::gateBlock($name);
        }

        if (is_array($host['ftp'] ?? null) || $normalized['via'] === 'ftp') {
            $ftp = is_array($host['ftp'] ?? null) ? $host['ftp'] : [];
            $normalized['ftp'] = [
                'host' => [
                    '_env' => ConfigWriter::envKeyFor($name, 'host', 'ftp'),
                    'default' => (string) ($ftp['host'] ?? ''),
                ],
                'user' => [
                    '_env' => ConfigWriter::envKeyFor($name, 'user', 'ftp'),
                    'default' => (string) ($ftp['user'] ?? ''),
                ],
                'password' => [
                    '_env' => ConfigWriter::envKeyFor($name, 'password', 'ftp'),
                    'default' => (string) ($ftp['password'] ?? ''),
                ],
            ];
        }

        return $normalized;
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
