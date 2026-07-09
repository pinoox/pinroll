<?php

namespace Pinoox\Pinroll\Console;

use InvalidArgumentException;
use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Support\ConfigFileLoader;
use Pinoox\Pinroll\Support\HostDir;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\ProjectPaths;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ConfigWizard
{
    /** @var array<string, array<string, mixed>> */
    private array $targets = [];

    public function __construct(
        private readonly SymfonyStyle $io,
        private readonly string $projectRoot,
        private readonly bool $force = false,
    ) {
    }

    /**
     * @return array{
     *     config_file: string,
     *     bundles_created: list<string>,
     *     targets: list<string>,
     *     pinion_targets: list<string>
     * }
     */
    public function run(): array
    {
        $paths = new NativePathResolver($this->projectRoot);
        $configFile = ProjectPaths::configFile($paths);

        $this->io->text([
            'Creates pinroll/pinroll.config.php step by step.',
        ]);

        $mode = (string) $this->io->choice(
            'Setup mode',
            [
                'ftp' => 'FTP production target',
                'custom' => 'Custom targets (pinion / ssh / ftp)',
            ],
            'ftp',
        );

        if ($mode === 'ftp') {
            $bundlesCreated = (new ProjectInitializer($this->projectRoot, $this->force))->init();
            $this->targets = ConnectionSetup::collect($this->io, 'production');
            ConfigWriter::write($configFile, $this->targets);

            return [
                'config_file' => $configFile,
                'bundles_created' => $bundlesCreated,
                'targets' => array_keys($this->targets),
                'pinion_targets' => [],
            ];
        }

        $bundlesCreated = (new ProjectInitializer($this->projectRoot, $this->force))->initBundles();

        if (ProjectPaths::isInitialized($paths) && !$this->force) {
            $this->handleExistingConfig($configFile);
        }

        do {
            $this->collectTarget();
        } while ($this->io->confirm('Add another target?', false));

        if ($this->targets === []) {
            throw new PinrollException('No targets configured. Run pinroll:init again or create pinroll/pinroll.config.php manually.');
        }

        ConfigWriter::write($configFile, $this->targets);

        $pinionTargets = array_keys(array_filter(
            $this->targets,
            static fn (array $target): bool => in_array('pinion', \Pinoox\Pinroll\Target\TargetTransport::names($target), true),
        ));

        return [
            'config_file' => $configFile,
            'bundles_created' => $bundlesCreated,
            'targets' => array_keys($this->targets),
            'pinion_targets' => $pinionTargets,
        ];
    }

    private function handleExistingConfig(string $configFile): void
    {
        $relative = $this->relativePath($configFile);
        $choice = $this->io->choice(
            "Config already exists at {$relative}. What do you want to do?",
            [
                'merge' => 'Add new targets (keep existing)',
                'replace' => 'Replace all targets',
                'skip' => 'Keep existing config and skip wizard',
            ],
            'merge',
        );

        if ($choice === 'skip') {
            throw new PinrollException('Wizard cancelled. Existing config kept at ' . $configFile);
        }

        if ($choice === 'merge') {
            $loaded = ConfigFileLoader::load($configFile);
            /** @var array<string, array<string, mixed>> $existing */
            $existing = is_array($loaded['targets'] ?? null) ? $loaded['targets'] : [];

            foreach ($existing as $name => $target) {
                if (!is_string($name) || !is_array($target)) {
                    continue;
                }
                $this->targets[$name] = ConfigWriter::normalizeTarget($name, $target);
            }

            if ($existing !== []) {
                $this->io->note('Loaded targets: ' . implode(', ', array_keys($existing)));
            }
        }
    }

    private function collectTarget(): void
    {
        $this->io->section('New target');

        $name = $this->askTargetName();
        $transport = $this->askTransport();

        $target = ['transport' => $transport];

        match ($transport) {
            'pinion' => $this->collectPinionFields($name, $target),
            'ssh' => $this->collectSshFields($name, $target),
            'ftp' => $this->collectFtpFields($name, $target),
            'local' => $this->collectLocalFields($target),
            default => throw new PinrollException('Unknown transport: ' . $transport),
        };

        $this->collectBundleFields($target);

        $this->targets[$name] = $target;

        $this->io->success("Target \"{$name}\" added ({$transport}).");
    }

    private function askTargetName(): string
    {
        return (string) $this->io->ask(
            'Target name (e.g. production, staging, local)',
            'production',
            function (mixed $value): string {
                $value = strtolower(trim((string) $value));

                if ($value === '') {
                    throw new \RuntimeException('Target name is required.');
                }

                if (!preg_match('/^[a-z][a-z0-9_-]*$/', $value)) {
                    throw new \RuntimeException('Use lowercase letters, numbers, hyphens or underscores. Start with a letter.');
                }

                if (isset($this->targets[$value])) {
                    throw new \RuntimeException("Target \"{$value}\" already exists.");
                }

                return $value;
            },
        );
    }

    private function askTransport(): string
    {
        $this->io->writeln('Transport options:');
        $this->io->listing([
            'ftp — FTP (recommended for shared hosting)',
            'pinion — HTTP via PinGate',
            'ssh — SSH / SFTP',
            'local — same-machine folder',
        ]);

        return (string) $this->io->choice(
            'Transport',
            [
                'ftp' => 'ftp (recommended)',
                'pinion' => 'pinion',
                'ssh' => 'ssh',
                'local' => 'local',
            ],
            'ftp',
        );
    }

    /**
     * @param array<string, mixed> $target
     */
    private function collectPinionFields(string $name, array &$target): void
    {
        $domain = trim((string) $this->io->ask(
            'Site domain (e.g. pinoox.com)',
            '',
            function (mixed $value): string {
                $value = trim((string) $value);
                if ($value === '') {
                    return '';
                }

                try {
                    return GateUrl::normalizeDomain($value);
                } catch (InvalidArgumentException $e) {
                    throw new \RuntimeException($e->getMessage());
                }
            },
        ));

        $target['dir'] = $this->askDir($domain);

        $suggestedUrl = $domain !== ''
            ? GateUrl::fromDomain($domain, $target['dir'])
            : '';

        $gateUrl = TargetHostSetup::askPinGateUrl($this->io, $target['dir'], $suggestedUrl);

        $target['gate_url'] = [
            '_env' => ConfigWriter::envKeyFor($name, 'gate_url'),
            'default' => $gateUrl,
        ];
        $target['token'] = [
            '_env' => ConfigWriter::envKeyFor($name, 'token'),
            'default' => '',
        ];
        $target['public_key'] = [
            '_env' => ConfigWriter::envKeyFor($name, 'public_key'),
            'default' => '',
        ];
    }

    /**
     * @param array<string, mixed> $target
     */
    private function collectSshFields(string $name, array &$target): void
    {
        $host = trim((string) $this->io->ask('SSH host / domain', 'pinoox.com'));
        $user = trim((string) $this->io->ask('SSH user', 'deploy'));

        $target['dir'] = $this->askDir($host);
        $target['host'] = ['_env' => ConfigWriter::envKeyFor($name, 'host'), 'default' => $host];
        $target['user'] = ['_env' => ConfigWriter::envKeyFor($name, 'user'), 'default' => $user];
        $target['key'] = ['_env' => ConfigWriter::envKeyFor($name, 'key'), 'default' => ''];
        $this->io->note('Upload path: ' . HostDir::incomingDir($target['dir']) . '/ — no PinGate (_pinoox) needed.');
    }

    /**
     * @param array<string, mixed> $target
     */
    private function collectFtpFields(string $name, array &$target): void
    {
        $host = trim((string) $this->io->ask('FTP host / domain', 'ftp.pinoox.com'));
        $user = trim((string) $this->io->ask('FTP user', 'ftpuser'));

        $domainHint = (string) preg_replace('/^ftp\./i', '', $host);
        $target['dir'] = $this->askDir($domainHint);
        $target['host'] = ['_env' => ConfigWriter::envKeyFor($name, 'host', 'ftp'), 'default' => $host];
        $target['user'] = ['_env' => ConfigWriter::envKeyFor($name, 'user', 'ftp'), 'default' => $user];
        $target['password'] = ['_env' => ConfigWriter::envKeyFor($name, 'password', 'ftp'), 'default' => ''];
        $this->io->note('Upload path: ' . HostDir::incomingDir($target['dir']) . '/ — PinGate is optional.');

        if ($this->io->confirm('Also configure PinGate for remote apply over HTTP?', false)) {
            $domain = trim((string) $this->io->ask(
                'Site domain for PinGate URL',
                $domainHint,
                function (mixed $value): string {
                    try {
                        return GateUrl::normalizeDomain((string) $value);
                    } catch (InvalidArgumentException $e) {
                        throw new \RuntimeException($e->getMessage());
                    }
                },
            ));
            $gateUrl = TargetHostSetup::askPinGateUrl(
                $this->io,
                $target['dir'],
                GateUrl::fromDomain($domain, $target['dir']),
            );
            $target['gate'] = SampleConfig::gateBlock($name, $gateUrl);
        }
    }

    /**
     * @param array<string, mixed> $target
     */
    private function collectLocalFields(array &$target): void
    {
        $target['path'] = trim((string) $this->io->ask(
            'Local incoming path (relative to project root)',
            'storage/pinroll/incoming',
        ));
    }

    /**
     * @param array<string, mixed> $target
     */
    private function collectBundleFields(array &$target): void
    {
        $this->io->writeln(BundleInputParser::HELP);

        $default = 'platform';
        $packages = ProjectPackages::list($this->projectRoot);
        if ($packages !== []) {
            $this->io->note('Installed apps: ' . implode(', ', $packages));
        }

        $input = trim((string) $this->io->ask('Release bundle', $default));
        $parsed = BundleInputParser::parse($input !== '' ? $input : $default);

        foreach ($parsed as $key => $value) {
            $target[$key] = $value;
        }
    }

    private function askDir(?string $domain = null): string
    {
        $suggested = $domain !== null && $domain !== '' ? HostDir::suggestFromDomain($domain) : '';
        $label = 'FTP deploy path (empty = login root; e.g. public_html or public_html/shop)';
        if ($suggested !== '') {
            $label .= ' — suggestion: public_html/' . $suggested;
        }

        return HostDir::normalize((string) $this->io->ask($label, ''));
    }

    private function relativePath(string $absolute): string
    {
        $root = rtrim($this->projectRoot, '/') . '/';

        if (str_starts_with($absolute, $root)) {
            return substr($absolute, strlen($root));
        }

        return $absolute;
    }
}
