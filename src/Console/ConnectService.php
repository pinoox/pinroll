<?php

namespace Pinoox\Pinroll\Console;

use InvalidArgumentException;
use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Host\HostGate;
use Pinoox\Pinroll\Pinroll;
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
        ?bool $interactivePick = null,
    ): array {
        PinrollAutoloader::register($this->projectRoot);
        $paths = new NativePathResolver($this->projectRoot);
        $configFile = ProjectPaths::configFile($paths);

        Pinroll::boot($paths);

        try {
            $raw = Pinroll::hosts()->raw($hostName);
        } catch (PinrollException) {
            OverlayWriter::patch($configFile, $hostName, ['via' => 'ftp']);
            Pinroll::boot($paths);
            $raw = Pinroll::hosts()->raw($hostName);
        }

        $kit = false;
        $cliVia = $via !== null ? strtolower(trim($via)) : '';
        $configuredVia = strtolower(trim((string) ($raw['via'] ?? '')));
        $pick = $interactivePick ?? true;

        if ($bootstrapFtp) {
            $cliVia = $cliVia !== '' ? $cliVia : 'ftp';
        }

        $checkVia = $cliVia !== '' ? $cliVia : ($configuredVia !== '' ? $configuredVia : 'ftp');
        if ($bootstrapFtp) {
            $checkVia = 'pinion';
        }

        if (!$reset && self::isSetupComplete($hostName, $checkVia) && $cliVia === '' && !$bootstrapFtp) {
            return $this->verifyExisting($io, $hostName, $checkVia);
        }

        if ($reset) {
            $io->section('Connect — reset setup');
            $io->writeln('  <fg=gray>Re-running deploy path, site URL, and PinGate setup…</>');
            $io->newLine();
        }

        if ($cliVia !== '') {
            $resolvedVia = $cliVia;
        } elseif ($pick) {
            $choice = $this->askMethod($io);
            $method = BootstrapKit::resolveMethod($choice);
            $resolvedVia = $method['via'];
            $bootstrapFtp = $bootstrapFtp || $method['bootstrap_ftp'];
            $kit = $method['kit'];
        } else {
            $resolvedVia = $configuredVia !== '' ? $configuredVia : 'ftp';
        }

        if ($bootstrapFtp && $resolvedVia === 'pinion') {
            $resolvedVia = 'ftp';
        }

        if ($resolvedVia === 'pinion') {
            $kit = true;
        }

        return $this->runSetup($io, $hostName, $resolvedVia, $bootstrapFtp, $configFile, $paths, $raw, $kit);
    }

    private function askMethod(SymfonyStyle $io): string
    {
        $io->section('Choose setup method');
        $io->writeln([
            '  <fg=gray>Pick how PinGate reaches the host. You can change later with</>',
            '  <comment>pinroll:connect --reset</comment> <fg=gray>or</> <comment>--via=…</comment>',
            '',
        ]);

        $labels = [];
        foreach (BootstrapKit::methodChoices() as $row) {
            $labels[$row['key']] = $row['label'];
        }

        return (string) $io->choice('Setup method', $labels, 'kit');
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
        bool $kit = false,
    ): array {
        if ($resolvedVia === 'ftp') {
            $this->assertFtpReady($io, $raw, $hostName);
        } elseif ($resolvedVia === 'ssh') {
            $this->assertSshReady($io, $raw, $hostName);
        }

        $io->section('Connect — ' . $hostName . ' (' . ($bootstrapFtp ? 'ftp → pinion' : $resolvedVia) . ')');

        $dirLabel = $resolvedVia === 'pinion'
            ? 'Deploy folder on host (usually public_html)'
            : 'FTP folder (subdomain folder at account root, e.g. apps)';
        $dirDefault = HostDir::fromHost($raw);
        $dir = HostDir::normalize((string) $io->ask($dirLabel, $dirDefault !== '' ? $dirDefault : 'public_html'));

        $gate = HostGate::credentials($raw, $resolvedVia);
        $siteDefault = $gate['site'] !== ''
            ? $gate['site']
            : ($gate['url'] !== '' ? GateUrl::siteFrom($gate['url']) : '');
        $siteUrl = trim((string) $io->ask(
            'Site URL (e.g. https://apps.example.com)',
            $siteDefault !== '' ? $siteDefault : null,
        ));
        if ($siteUrl === '') {
            throw new PinrollException('Site URL is required.');
        }

        $siteUrl = GateUrl::siteFrom($this->resolveGateUrl($siteUrl));
        $gateUrl = GateUrl::expand($siteUrl);
        $webPath = HostDir::dirFromGateUrl($gateUrl);
        $io->writeln('  <fg=gray>Site:</> <comment>' . $siteUrl . '</comment>');
        $io->writeln('  <fg=gray>PinGate URL:</> <comment>' . $gateUrl . '</comment>');

        $ftpPassword = null;
        if ($resolvedVia === 'ftp') {
            $ftpPassword = self::envOr(
                ConfigWriter::envKeyFor($hostName, 'password', 'ftp'),
                $raw['ftp']['password'] ?? null,
            );
        }

        $saveVia = $bootstrapFtp ? 'pinion' : $resolvedVia;
        $this->saveHost($configFile, $hostName, $dir, $siteUrl, $saveVia, $webPath, $ftpPassword);
        Pinroll::boot($paths);

        $upload = $resolvedVia !== 'pinion';
        $buildKit = $kit || $resolvedVia === 'pinion';

        if ($buildKit && !$upload) {
            $io->writeln('  <fg=gray>Building extractable PinGate kit…</>');
        } elseif ($upload) {
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
                $buildKit,
                $dir,
                $gateUrl,
                false,
                $upload,
                false,
                null,
                $buildKit,
            );
        } finally {
            PushProgress::bind(null);
        }

        return array_merge([
            'host' => $hostName,
            'target' => $hostName,
            'mode' => 'setup',
            'method' => $buildKit ? 'kit' : $saveVia,
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
        if ($gate['site'] !== '') {
            $io->writeln('  <fg=gray>Site</>        <comment>' . $gate['site'] . '</comment>');
        }
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
                'FTP is not configured yet.',
                'Set host/user in .pinoox/pinroll.config.php or .env, then run pinroll:connect again:',
                '  ' . $hostKey . '=',
                '  ' . $userKey . '=',
                '  ' . $passKey . '=',
            ]);

            throw new PinrollException('Missing FTP credentials');
        }

        if ($password === '') {
            $entered = $io->askHidden('FTP password');
            if (is_string($entered) && trim($entered) !== '') {
                OverlayWriter::patch(
                    ProjectPaths::configFile(new NativePathResolver($this->projectRoot)),
                    $hostName,
                    ['ftp' => ['password' => trim($entered)]],
                );
                $raw['ftp'] = is_array($raw['ftp'] ?? null) ? $raw['ftp'] : [];
                $raw['ftp']['password'] = trim($entered);
            } elseif (!$io->confirm('FTP password is empty. Continue anyway?', false)) {
                throw new PinrollException('FTP password required');
            }
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
            throw new PinrollException('Missing SSH credentials for host ' . $hostName);
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

    private function resolveGateUrl(string $siteUrl): string
    {
        try {
            return GateUrl::normalizeInput($siteUrl);
        } catch (InvalidArgumentException $e) {
            throw new PinrollException($e->getMessage());
        }
    }

    private function saveHost(
        string $configFile,
        string $hostName,
        string $dir,
        string $siteUrl,
        string $via,
        ?string $webPath = null,
        ?string $ftpPassword = null,
    ): void {
        $patch = [
            'deploy_path' => $dir,
            'dir' => $dir,
            'via' => $via,
            'gate' => [
                'site' => GateUrl::siteFrom($siteUrl),
            ],
        ];
        if ($webPath !== null) {
            $patch['web_path'] = $webPath;
        }
        if ($ftpPassword !== null && $ftpPassword !== '') {
            $patch['ftp'] = ['password' => $ftpPassword];
        }

        OverlayWriter::patch($configFile, $hostName, $patch);
    }
}
