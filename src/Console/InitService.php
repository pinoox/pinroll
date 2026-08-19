<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\PinrollAutoloader;
use Pinoox\Pinroll\Support\ProjectPaths;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Non-interactive scaffold: .pinoox/pinroll.config.php + .env key stubs.
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

        $keys = [
            ConfigWriter::envKeyFor($targetName, 'host', 'ftp') => '',
            ConfigWriter::envKeyFor($targetName, 'user', 'ftp') => '',
            ConfigWriter::envKeyFor($targetName, 'password', 'ftp') => '',
            ConfigWriter::envKeyFor($targetName, 'url', 'pinion') => '',
            ConfigWriter::envKeyFor($targetName, 'token', 'pinion') => '',
        ];

        if (in_array($targetName, ['production', 'prod'], true)) {
            $keys = array_merge([
                'PINROLL_VIA' => 'ftp',
                'PINROLL_PATH' => 'public_html',
                'PINROLL_KEEP' => '3',
                'PINROLL_STORE' => 'remote',
                'PINROLL_AUTO_CLEAN' => 'true',
                'PINROLL_APPS' => '',
                'PINROLL_URL' => '',
                'PINROLL_TOKEN' => '',
                'PINROLL_HOST' => '',
                'PINROLL_USER' => '',
                'PINROLL_PASSWORD' => '',
                'PINROLL_LANG' => 'en',
                'PINROLL_DB_HOST' => 'localhost',
                'PINROLL_DB_DATABASE' => 'pinoox',
                'PINROLL_DB_USERNAME' => '',
                'PINROLL_DB_PASSWORD' => '',
                'PINROLL_DB_CONNECTION' => 'mysql',
                'PINROLL_DB_PORT' => '3306',
                'PINROLL_DB_PREFIX' => 'pin_',
                'PINROLL_DB_TIMEZONE' => '+03:30',
                'PINROLL_ADMIN_FNAME' => 'support',
                'PINROLL_ADMIN_LNAME' => 'pinoox',
                'PINROLL_ADMIN_EMAIL' => 'info@pinoox.com',
                'PINROLL_ADMIN_USERNAME' => 'admin',
                'PINROLL_ADMIN_PASSWORD' => '123456',
                'PINROLL_BUILD_EXCLUDE' => '',
                'PINROLL_BUILD_INCLUDE' => '',
            ], $keys);
        }

        return $keys;
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
        $before = (string) file_get_contents($configFile);
        $hostPattern = '/^\s+' . preg_quote(var_export($hostName, true), '/') . '\s*=>/m';
        if (preg_match($hostPattern, $before)) {
            return false;
        }

        ConfigWriter::addHost($configFile, $hostName);

        $after = (string) file_get_contents($configFile);

        return $after !== $before;
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
