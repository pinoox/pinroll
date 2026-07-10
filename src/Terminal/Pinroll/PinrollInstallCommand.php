<?php

namespace Pinoox\Terminal\Pinroll;

use Pinoox\Component\Terminal;
use Pinoox\Pinroll\Console\DeployRunner;
use Pinoox\Pinroll\Console\PinrollCli;
use Pinoox\Pinroll\Console\ReleaseApplier;
use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Host\HostGate;
use Pinoox\Pinroll\Host\HostSelector;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Rollout\RolloutSession;
use Pinoox\Pinroll\Support\IncomingRelease;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\PushProgress;
use Pinoox\Pinroll\Target\PinGateClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pinroll:install',
    description: 'Install a staged release on a host via PinGate/SSH (or --local on the host)',
    aliases: ['pinroll:apply'],
)]
class PinrollInstallCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->addArgument('host', InputArgument::OPTIONAL, 'Host name (omit when default_host is set)')
            ->addArgument('deploy_id', InputArgument::OPTIONAL, 'Deploy id from pinroll:push (omit = latest)')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host override')
            ->addOption('app', null, InputOption::VALUE_REQUIRED, 'App package (pick latest staged release for this app)')
            ->addOption('package', null, InputOption::VALUE_REQUIRED, 'Deprecated — use --app')
            ->addOption('local', null, InputOption::VALUE_NONE, 'Install on this machine only')
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'List staged releases')
            ->addOption('via', null, InputOption::VALUE_REQUIRED, 'Transport override: ftp, ssh, pinion');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);

        try {
            $root = defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd();
            Pinroll::boot(new NativePathResolver((string) $root));

            $local = (bool) $input->getOption('local');
            [$hostName, $deployId] = $this->resolveArgs($input, $local);
            $via = (string) ($input->getOption('via') ?: '');
            $appFilter = trim((string) ($input->getOption('app') ?: $input->getOption('package') ?: ''));

            if ($input->getOption('list')) {
                if ($local) {
                    $this->printLocalIncomingList($io);
                } else {
                    $this->printRemoteIncomingList($io, $hostName, $via !== '' ? $via : null);
                }

                return Command::SUCCESS;
            }

            if ($local) {
                return $this->installLocal($io, $deployId, $appFilter);
            }

            return $this->installRemote($io, $hostName, $deployId, $via !== '' ? $via : null, $appFilter);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function resolveArgs(InputInterface $input, bool $local): array
    {
        $first = (string) ($input->getArgument('host') ?? '');
        $second = $input->getArgument('deploy_id');
        $second = is_string($second) && $second !== '' ? $second : null;

        if ($local) {
            if ($second !== null) {
                return ['local', $second];
            }

            if ($first !== '' && $this->looksLikeDeployId($first)) {
                return ['local', $first];
            }

            return ['local', null];
        }

        $known = Pinroll::hosts()->names();
        $firstIsHost = $first !== '' && (
            in_array($first, $known, true)
            || in_array(strtolower($first), ['prod', 'stg', 'production', 'staging'], true)
        );

        if (!$firstIsHost && $second === null && $first !== '' && $this->looksLikeDeployId($first)) {
            $default = Pinroll::hosts()->defaultHostName() ?? 'production';

            return [$default, $first];
        }

        if ($firstIsHost) {
            return [$first, $second];
        }

        $hostName = HostSelector::resolve($input, $first !== '' ? $first : null);

        return [$hostName, $second];
    }

    private function looksLikeDeployId(string $value): bool
    {
        return (bool) preg_match('/^\d{8}_\d{6}_[a-f0-9]+$/i', $value);
    }

    private function installLocal(SymfonyStyle $io, ?string $deployId, string $appFilter): int
    {
        $incoming = Pinroll::config()->storage((string) Pinroll::config()->get('incoming_path', 'pinroll/incoming'));

        if ($deployId === null) {
            $deployId = $this->resolveLatestId(IncomingRelease::list($incoming), $appFilter);
            if ($deployId === null) {
                throw new PinrollException('No staged release in ' . $incoming . '. Upload with pinroll:push first.');
            }

            $io->writeln('<fg=gray>Using latest (local):</> <info>' . $deployId . '</info>');
        }

        $result = (new DeployRunner())->apply($deployId);
        PinrollCli::printInstallResult($io, $result);

        return Command::SUCCESS;
    }

    private function installRemote(SymfonyStyle $io, string $hostName, ?string $deployId, ?string $via, string $appFilter): int
    {
        $resolved = Pinroll::hosts()->resolve($hostName, $via);
        $raw = Pinroll::hosts()->raw($hostName);

        if ($deployId === null || $deployId === '') {
            $deployId = $this->resolveRemoteLatestId($raw, $resolved, $appFilter) ?? '';
            if ($deployId !== '') {
                $io->writeln('<fg=gray>Using latest on</> <info>' . $hostName . '</info><fg=gray>:</> <comment>' . $deployId . '</comment>');
            } else {
                $io->writeln('<fg=gray>Installing latest staged release on</> <info>' . $hostName . '</info>');
            }
        }

        $io->writeln('');
        $io->block(
            'pinroll:install  →  ' . $hostName,
            'INFO',
            'fg=black;bg=cyan',
            ' ',
            true,
        );

        $channel = $this->installChannel($resolved, $raw);
        $io->definitionList(
            ['Host' => '<info>' . $hostName . '</info>'],
            ['Channel' => '<comment>' . $channel . '</comment>'],
            ['Deploy' => $deployId !== '' ? '<info>' . $deployId . '</info>' : '<fg=gray>latest on host</>'],
        );
        $io->newLine();

        PushProgress::bind(
            static function (string $message) use ($io): void {
                if ($message !== '') {
                    $io->writeln($message);
                }
            },
        );

        try {
            $session = RolloutSession::create(Pinroll::config(), $hostName, 'install', (string) ($resolved['transport'] ?? 'remote'));
            (new ReleaseApplier())->applyOnTarget($resolved, $raw, $deployId, $session);

            $result = array_merge($session->toArray(), [
                'deploy_id' => $deployId !== '' ? $deployId : ($session->toArray()['deploy_id'] ?? ''),
            ]);
            PinrollCli::printInstallResult($io, $result);

            return Command::SUCCESS;
        } finally {
            PushProgress::bind(null);
        }
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $resolved
     */
    private function installChannel(array $resolved, array $raw): string
    {
        if ((string) ($resolved['transport'] ?? '') === 'local') {
            return 'local';
        }

        if (HostGate::isConfigured($raw)) {
            return 'PinGate';
        }

        if ((string) ($resolved['transport'] ?? '') === 'ssh' || is_array($raw['ssh'] ?? null)) {
            return 'SSH';
        }

        return (string) ($resolved['transport'] ?? 'remote');
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $resolved
     */
    private function resolveRemoteLatestId(array $raw, array $resolved, string $appFilter): ?string
    {
        $gate = HostGate::credentials($raw);
        $gateUrl = $gate['url'] !== '' ? $gate['url'] : (string) ($resolved['gate_url'] ?? '');
        $token = $gate['token'] !== '' ? $gate['token'] : (string) ($resolved['token'] ?? '');

        if ($gateUrl === '' || $token === '') {
            return null;
        }

        try {
            $incoming = PinGateClient::incoming($gateUrl, $token);
            $releases = is_array($incoming['releases'] ?? null) ? $incoming['releases'] : [];

            return $this->resolveLatestId($releases, $appFilter);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param list<array{id?: string, path?: string}> $releases
     */
    private function resolveLatestId(array $releases, string $appFilter): ?string
    {
        foreach ($releases as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = (string) ($row['id'] ?? '');
            $path = (string) ($row['path'] ?? '');
            if ($id === '') {
                continue;
            }

            if ($appFilter !== '' && !str_contains(strtolower($path), strtolower($appFilter))) {
                continue;
            }

            return $id;
        }

        return isset($releases[0]['id']) ? (string) $releases[0]['id'] : null;
    }

    private function printLocalIncomingList(SymfonyStyle $io): void
    {
        $incoming = Pinroll::config()->storage((string) Pinroll::config()->get('incoming_path', 'pinroll/incoming'));
        $this->printIncomingTable($io, IncomingRelease::list($incoming), 'local incoming/');
        $io->writeln('<fg=gray>Install local:</> <comment>php pinoox pinroll:install --local</comment>');
        $io->writeln('<fg=gray>Install local id:</> <comment>php pinoox pinroll:install --local {deploy_id}</comment>');
    }

    private function printRemoteIncomingList(SymfonyStyle $io, string $hostName, ?string $via): void
    {
        $resolved = Pinroll::hosts()->resolve($hostName, $via);
        $raw = Pinroll::hosts()->raw($hostName);
        $gate = HostGate::credentials($raw);
        $gateUrl = $gate['url'] !== '' ? $gate['url'] : (string) ($resolved['gate_url'] ?? '');
        $token = $gate['token'] !== '' ? $gate['token'] : (string) ($resolved['token'] ?? '');

        if ($gateUrl === '' || $token === '') {
            throw new PinrollException(implode("\n", HostGate::setupGuide($hostName)));
        }

        try {
            $incoming = PinGateClient::incoming($gateUrl, $token);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (str_contains(strtolower($message), 'unknown pingate route') || str_contains($message, 'incoming')) {
                throw new PinrollException(
                    "PinGate on the host is outdated (no /incoming).\n"
                    . "Re-upload: php pinoox pinroll:gate {$hostName}\n"
                    . "Then install: php pinoox pinroll:install {$hostName}",
                );
            }

            throw $e;
        }

        $releases = [];
        foreach ($incoming['releases'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $releases[] = [
                'id' => (string) ($row['id'] ?? ''),
                'path' => (string) ($row['path'] ?? ''),
                'size' => (int) ($row['size'] ?? 0),
                'mtime' => (int) ($row['mtime'] ?? 0),
            ];
        }

        $this->printIncomingTable($io, $releases, 'host ' . $hostName . ' (PinGate)');
        $io->writeln('<fg=gray>Install latest:</> <comment>php pinoox pinroll:install ' . $hostName . '</comment>');
        $io->writeln('<fg=gray>Install specific:</> <comment>php pinoox pinroll:install ' . $hostName . ' {deploy_id}</comment>');
    }

    /**
     * @param list<array{id: string, path?: string, size: int, mtime: int}> $releases
     */
    private function printIncomingTable(SymfonyStyle $io, array $releases, string $label): void
    {
        if ($releases === []) {
            $io->warning('No staged releases on ' . $label);

            return;
        }

        $io->section('Incoming releases — ' . $label);
        $rows = [];
        foreach ($releases as $index => $release) {
            $rows[] = [
                $index === 0 ? '<fg=green>latest</>' : '',
                $release['id'],
                $this->formatBytes($release['size']),
                $release['mtime'] > 0 ? date('Y-m-d H:i:s', $release['mtime']) : '—',
            ];
        }

        $io->table(['', 'Deploy ID', 'Size', 'Uploaded'], $rows);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / (1024 * 1024), 1) . ' MB';
    }
}
