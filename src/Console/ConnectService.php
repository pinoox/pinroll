<?php

namespace Pinoox\Pinroll\Console;

use InvalidArgumentException;
use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\ConfigFileLoader;
use Pinoox\Pinroll\Support\HostDir;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\PinrollAutoloader;
use Pinoox\Pinroll\Support\ProjectPaths;
use Pinoox\Pinroll\Support\PushConsole;
use Pinoox\Pinroll\Support\PushProgress;
use Pinoox\Pinroll\Target\TargetGate;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Connect host: ask deploy path + public domain, upload PinGate via FTP.
 */
final class ConnectService
{
    public function __construct(
        private readonly string $projectRoot,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(SymfonyStyle $io, string $targetName = 'production'): array
    {
        PinrollAutoloader::register($this->projectRoot);
        $paths = new NativePathResolver($this->projectRoot);
        $configFile = ProjectPaths::configFile($paths);

        if (!is_file($configFile)) {
            throw new PinrollException(
                'Pinroll is not initialized. Run: php pinoox pinroll:init',
            );
        }

        Pinroll::configure([], $paths);
        $raw = Pinroll::targets()->raw($targetName);

        $this->assertFtpReady($io, $raw, $targetName);

        $io->section('Connect — ' . $targetName);

        $dirDefault = HostDir::fromTarget($raw);
        if ($dirDefault === '') {
            $dirDefault = 'public_html';
        }

        $dir = HostDir::normalize((string) $io->ask(
            'Deploy path (FTP path, e.g. public_html or public_html/shop)',
            $dirDefault,
        ));
        if ($dir === '') {
            $dir = 'public_html';
        }

        $web = HostDir::webPath($dir);
        $siteDefault = 'https://' . TargetGate::EXAMPLE_DOMAIN . ($web !== '' ? '/' . $web : '');
        $siteUrl = trim((string) $io->ask(
            'Public site URL (e.g. https://pinoox.com)',
            $siteDefault,
        ));

        $gateUrl = $this->resolveGateUrl($siteUrl, $dir);
        $io->writeln('  <fg=gray>PinGate URL:</> <comment>' . $gateUrl . '</comment>');

        $this->saveTarget($configFile, $targetName, $dir, $gateUrl);
        Pinroll::configure([], $paths);

        $io->writeln('  <fg=gray>Building & uploading PinGate…</>');
        $io->writeln('  <fg=gray>(vendor copy + FTP — progress below)</>');
        PushProgress::bind(
            static function (string $message, string $style = PushConsole::STYLE_DEFAULT) use ($io): void {
                $formatted = PushConsole::format($message, $style);
                if ($formatted === '') {
                    $io->newLine();
                } else {
                    $io->writeln($formatted);
                }
            },
            false,
            static function (int $current, int $total, string $label) use ($io): void {
                if ($total <= 0) {
                    return;
                }
                if ($current === 1 || $current === $total || $current % 50 === 0) {
                    $suffix = $label !== '' ? ' ' . $label : '';
                    $io->writeln(sprintf('  <fg=gray>%d/%d%s</>', $current, $total, $suffix));
                }
            },
        );

        try {
            $gate = (new DeployRunner($this->projectRoot))->initGate(
                $targetName,
                false,
                $dir,
                $gateUrl,
                false,
                true,
            );
        } finally {
            PushProgress::bind(null);
        }

        return array_merge(['target' => $targetName], $gate);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function assertFtpReady(SymfonyStyle $io, array $raw, string $targetName): void
    {
        $hostKey = ConfigWriter::envKeyFor($targetName, 'host', 'ftp');
        $userKey = ConfigWriter::envKeyFor($targetName, 'user', 'ftp');
        $passKey = ConfigWriter::envKeyFor($targetName, 'password', 'ftp');

        $host = self::envOr($hostKey, $raw['ftp']['host'] ?? null);
        $user = self::envOr($userKey, $raw['ftp']['user'] ?? null);
        $password = self::envOr($passKey, $raw['ftp']['password'] ?? null);

        if ($host === '' || $user === '') {
            $io->error([
                'FTP is not configured in .env yet.',
                'Set these keys, then run pinroll:connect again:',
                '  ' . $hostKey . '=',
                '  ' . $userKey . '=',
                '  ' . $passKey . '=',
            ]);

            throw new PinrollException('Missing FTP credentials in .env');
        }

        if ($password === '' && !$io->confirm('FTP password is empty. Continue anyway?', false)) {
            throw new PinrollException('FTP password required in .env (' . $passKey . ')');
        }
    }

    private static function envOr(string $key, mixed $fallback): string
    {
        $fromEnv = getenv($key);
        if (is_string($fromEnv) && $fromEnv !== '') {
            return trim($fromEnv);
        }
        if (isset($_ENV[$key]) && is_string($_ENV[$key]) && $_ENV[$key] !== '') {
            return trim($_ENV[$key]);
        }
        if (is_string($fallback)) {
            return trim($fallback);
        }

        return '';
    }

    private function resolveGateUrl(string $siteUrl, string $dir): string
    {
        try {
            return GateUrl::normalizeInput($siteUrl, $dir);
        } catch (InvalidArgumentException $e) {
            try {
                $domain = GateUrl::normalizeDomain($siteUrl);

                return GateUrl::fromDomain($domain, $dir);
            } catch (InvalidArgumentException) {
                throw new PinrollException($e->getMessage());
            }
        }
    }

    private function saveTarget(string $configFile, string $targetName, string $dir, string $gateUrl): void
    {
        $loaded = ConfigFileLoader::load($configFile);
        /** @var array<string, array<string, mixed>> $targets */
        $targets = is_array($loaded['targets'] ?? null) ? $loaded['targets'] : [];

        if (!isset($targets[$targetName]) || !is_array($targets[$targetName])) {
            $targets[$targetName] = SampleConfig::productionTarget($targetName);
        }

        $targets[$targetName]['dir'] = $dir;
        $targets[$targetName]['via'] = (string) ($targets[$targetName]['via'] ?? 'ftp');
        $targets[$targetName]['gate'] = SampleConfig::gateBlock($targetName, $gateUrl);

        if (!isset($targets[$targetName]['ftp']) || !is_array($targets[$targetName]['ftp'])) {
            $targets[$targetName]['ftp'] = SampleConfig::productionTarget($targetName)['ftp'];
        }

        ConfigWriter::write($configFile, $targets);
    }
}
