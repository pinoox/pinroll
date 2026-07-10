<?php

namespace Pinoox\Pinroll\Console;

use InvalidArgumentException;
use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Host\HostGate;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\ConfigFileLoader;
use Pinoox\Pinroll\Support\HostDir;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\PinrollAutoloader;
use Pinoox\Pinroll\Support\ProjectPaths;
use Pinoox\Pinroll\Support\PushConsole;
use Pinoox\Pinroll\Support\PushProgress;
use Pinoox\Pinroll\Target\TargetChecker;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Connect host: deploy path + site URL + PinGate setup (transport-aware).
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
    public function run(
        SymfonyStyle $io,
        string $hostName = 'production',
        ?string $via = null,
        bool $bootstrapFtp = false,
        bool $reset = false,
    ): array {
        PinrollAutoloader::register($this->projectRoot);
        $paths = new NativePathResolver($this->projectRoot);
        $configFile = ProjectPaths::configFile($paths);

        if (!is_file($configFile)) {
            throw new PinrollException(
                'Pinroll is not initialized. Run: php pinoox pinroll:init',
            );
        }

        Pinroll::boot($paths);
        $raw = Pinroll::hosts()->raw($hostName);
        $resolvedVia = strtolower(trim($via ?? (string) ($raw['via'] ?? 'ftp')));
        if ($resolvedVia === '') {
            $resolvedVia = 'ftp';
        }

        if ($bootstrapFtp && $resolvedVia === 'pinion') {
            $resolvedVia = 'ftp';
        }

        if (!$reset && self::isSetupComplete($hostName, $resolvedVia)) {
            return $this->verifyExisting($io, $hostName, $resolvedVia);
        }

        if ($reset) {
            $io->section('Connect — reset setup');
            $io->writeln('  <fg=gray>Re-running deploy path, site URL, and PinGate setup…</>');
            $io->newLine();
        }

        return $this->runSetup($io, $hostName, $resolvedVia, $bootstrapFtp, $configFile, $paths, $raw);
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function runSetup(
        SymfonyStyle $io,
        string $hostName,
        string $resolvedVia,
        bool $bootstrapFtp,
        string $configFile,
        NativePathResolver $paths,
        array $raw,
    ): array {
        if ($resolvedVia === 'ftp') {
            $this->assertFtpReady($io, $raw, $hostName);
        } elseif ($resolvedVia === 'ssh') {
            $this->assertSshReady($io, $raw, $hostName);
        }

        $io->section('Connect — ' . $hostName . ' (' . $resolvedVia . ')');

        $dirDefault = HostDir::fromHost($raw);
        if ($dirDefault === '') {
            $dirDefault = 'public_html';
        }

        $dir = HostDir::normalize((string) $io->ask(
            'Deploy path (e.g. public_html or public_html/shop)',
            $dirDefault,
        ));
        if ($dir === '') {
            $dir = 'public_html';
        }

        $web = HostDir::webPath($dir);
        $gate = HostGate::credentials($raw, $resolvedVia);
        $siteDefault = $gate['url'] !== ''
            ? (string) preg_replace('#/pingate\.php.*$#i', '', rtrim($gate['url'], '/'))
            : 'https://' . HostGate::EXAMPLE_DOMAIN . ($web !== '' ? '/' . $web : '');
        $siteUrl = trim((string) $io->ask(
            'Public site URL (e.g. https://pinoox.com)',
            $siteDefault,
        ));

        $gateUrl = $this->resolveGateUrl($siteUrl, $dir);
        $io->writeln('  <fg=gray>PinGate URL:</> <comment>' . $gateUrl . '</comment>');

        $saveVia = $bootstrapFtp ? 'pinion' : $resolvedVia;
        $this->saveHost($configFile, $hostName, $dir, $gateUrl, $saveVia);
        Pinroll::boot($paths);

        $upload = $resolvedVia !== 'pinion';
        if ($resolvedVia === 'pinion') {
            $io->note([
                'Pinion transport: upload pingate.php + gate/ to the host manually,',
                'or run: php pinoox pinroll:gate ' . $hostName . ' -z',
                'Optional one-time FTP bootstrap: pinroll:connect --bootstrap-ftp',
            ]);
        } else {
            $io->writeln('  <fg=gray>Building & uploading PinGate…</>');
        }

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
                $hostName,
                false,
                $dir,
                $gateUrl,
                false,
                $upload,
            );
        } finally {
            PushProgress::bind(null);
        }

        return array_merge([
            'host' => $hostName,
            'target' => $hostName,
            'mode' => 'setup',
        ], $gate);
    }

    /**
     * @return array<string, mixed>
     */
    private function verifyExisting(SymfonyStyle $io, string $hostName, string $resolvedVia): array
    {
        $raw = Pinroll::hosts()->raw($hostName);
        $deployPath = HostDir::fromHost($raw);
        $gate = HostGate::credentials($raw, $resolvedVia);

        $io->section('Connect — ' . $hostName . ' (' . $resolvedVia . ')');
        $io->writeln('  <fg=gray>Status</>   <info>configured</info> <fg=gray>(use --reset to run setup again)</>');
        $io->writeln('  <fg=gray>Deploy path</>  <comment>' . $deployPath . '</comment>');
        if ($gate['url'] !== '') {
            $io->writeln('  <fg=gray>PinGate URL</>  <comment>' . $gate['url'] . '</comment>');
        }
        $io->newLine();
        $io->writeln('  <fg=gray>Testing connection…</>');

        $check = (new TargetChecker($this->projectRoot))->check($hostName, $resolvedVia);

        return [
            'host' => $hostName,
            'target' => $hostName,
            'mode' => 'verified',
            'gate_url' => $gate['url'],
            'deploy_path' => $deployPath,
            'transport' => $resolvedVia,
            'check' => $check,
            'uploaded' => false,
        ];
    }

    public static function isSetupComplete(string $hostName, string $via): bool
    {
        $resolved = Pinroll::hosts()->resolve($hostName, $via);
        $raw = Pinroll::hosts()->raw($hostName);

        if (HostDir::fromHost($resolved) === '') {
            return false;
        }

        $gate = HostGate::credentials($raw, $via);
        if ($gate['url'] === '') {
            return false;
        }

        return match ($via) {
            'ftp' => trim((string) ($resolved['host'] ?? '')) !== ''
                && trim((string) ($resolved['user'] ?? '')) !== '',
            'ssh' => trim((string) ($resolved['host'] ?? '')) !== ''
                && trim((string) ($resolved['user'] ?? '')) !== '',
            'pinion' => $gate['url'] !== '',
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function assertFtpReady(SymfonyStyle $io, array $raw, string $hostName): void
    {
        $hostKey = ConfigWriter::envKeyFor($hostName, 'host', 'ftp');
        $userKey = ConfigWriter::envKeyFor($hostName, 'user', 'ftp');
        $passKey = ConfigWriter::envKeyFor($hostName, 'password', 'ftp');

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

    /**
     * @param array<string, mixed> $raw
     */
    private function assertSshReady(SymfonyStyle $io, array $raw, string $hostName): void
    {
        $hostKey = ConfigWriter::envKeyFor($hostName, 'host', 'ssh');
        $userKey = ConfigWriter::envKeyFor($hostName, 'user', 'ssh');

        $host = self::envOr($hostKey, $raw['ssh']['host'] ?? null);
        $user = self::envOr($userKey, $raw['ssh']['user'] ?? null);

        if ($host === '' || $user === '') {
            throw new PinrollException('Missing SSH credentials in .env for host ' . $hostName);
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

    private function saveHost(string $configFile, string $hostName, string $dir, string $gateUrl, string $via): void
    {
        $loaded = ConfigFileLoader::load($configFile);
        /** @var array<string, array<string, mixed>> $hosts */
        $hosts = is_array($loaded['hosts'] ?? null) ? $loaded['hosts'] : [];
        if ($hosts === [] && is_array($loaded['targets'] ?? null)) {
            $hosts = $loaded['targets'];
        }

        if (!isset($hosts[$hostName]) || !is_array($hosts[$hostName])) {
            $hosts[$hostName] = SampleConfig::productionHost($hostName);
        }

        $hosts[$hostName]['deploy_path'] = $dir;
        $hosts[$hostName]['dir'] = $dir;
        $hosts[$hostName]['via'] = $via;
        $hosts[$hostName]['gate'] = SampleConfig::gateBlock($hostName, $gateUrl);

        if (!isset($hosts[$hostName]['ftp']) || !is_array($hosts[$hostName]['ftp'])) {
            $hosts[$hostName]['ftp'] = SampleConfig::productionHost($hostName)['ftp'];
        }

        ConfigWriter::writeHosts($configFile, $hosts, SampleConfig::globalDefaults());
    }
}
