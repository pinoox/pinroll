<?php

namespace Pinoox\Terminal\Pinroll;

use Pinoox\Component\Terminal;
use Pinoox\Pinroll\Console\DeployRunner;
use Pinoox\Pinroll\Console\PinrollCli;
use Pinoox\Pinroll\Console\ReleaseApplier;
use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Rollout\RolloutSession;
use Pinoox\Pinroll\Support\IncomingRelease;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\PushProgress;
use Pinoox\Pinroll\Target\PinGateClient;
use Pinoox\Pinroll\Target\TargetGate;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pinroll:apply',
    description: 'Apply a staged release on a target via PinGate/SSH (or --local on the host)',
)]
class PinrollApplyCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->addArgument('target', InputArgument::OPTIONAL, 'Target name (prod = production)', 'production')
            ->addArgument('deploy_id', InputArgument::OPTIONAL, 'Deploy id from pinroll:push (omit = latest on target)')
            ->addOption('local', null, InputOption::VALUE_NONE, 'Apply on this machine only (host/SSH; not for laptop → production)')
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'List staged releases on the target (or local with --local)')
            ->addOption('via', null, InputOption::VALUE_REQUIRED, 'Transport override: ftp, ssh, pinion');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);

        try {
            $root = defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd();
            Pinroll::configure([], new NativePathResolver((string) $root));

            $local = (bool) $input->getOption('local');
            [$targetName, $deployId] = $this->resolveArgs($input, $local);
            $via = (string) ($input->getOption('via') ?: '');

            if ($input->getOption('list')) {
                if ($local) {
                    $this->printLocalIncomingList($io);
                } else {
                    $this->printRemoteIncomingList($io, $targetName, $via !== '' ? $via : null);
                }

                return Command::SUCCESS;
            }

            if ($local) {
                return $this->applyLocal($io, $deployId);
            }

            return $this->applyRemote($io, $targetName, $deployId, $via !== '' ? $via : null);
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
        $first = (string) ($input->getArgument('target') ?? 'production');
        $second = $input->getArgument('deploy_id');
        $second = is_string($second) && $second !== '' ? $second : null;

        $known = Pinroll::targets()->names();
        $firstIsTarget = in_array($first, $known, true)
            || in_array(strtolower($first), ['prod', 'stg', 'production', 'staging'], true);

        // --local: first positional may be deploy_id (target is unused)
        if ($local) {
            if ($second !== null) {
                return ['local', $second];
            }

            if ($firstIsTarget) {
                return ['local', null];
            }

            return ['local', $first !== '' ? $first : null];
        }

        // BC: pinroll:apply {deploy_id} → production + that id
        if (!$firstIsTarget && $second === null && $this->looksLikeDeployId($first)) {
            return ['production', $first];
        }

        return [$first, $second];
    }

    private function looksLikeDeployId(string $value): bool
    {
        return (bool) preg_match('/^\d{8}_\d{6}_[a-f0-9]+$/i', $value);
    }

    private function applyLocal(SymfonyStyle $io, ?string $deployId): int
    {
        $incoming = Pinroll::config()->storage((string) Pinroll::config()->get('incoming_path', 'pinroll/incoming'));

        if ($deployId === null) {
            $latest = IncomingRelease::list($incoming)[0] ?? null;
            if ($latest === null) {
                throw new PinrollException('No staged release in ' . $incoming . '. Upload with pinroll:push first.');
            }

            $io->writeln('<fg=gray>Using latest (local):</> <info>' . $latest['id'] . '</info>');
        }

        $result = (new DeployRunner())->apply($deployId);
        PinrollCli::printApplyResult($io, $result);

        return Command::SUCCESS;
    }

    private function applyRemote(SymfonyStyle $io, string $targetName, ?string $deployId, ?string $via): int
    {
        $resolved = Pinroll::targets()->resolve($targetName, $via);
        $raw = Pinroll::targets()->raw($targetName);

        if ($deployId === null || $deployId === '') {
            $deployId = $this->resolveRemoteLatestId($raw, $resolved) ?? '';
            if ($deployId !== '') {
                $io->writeln('<fg=gray>Using latest on</> <info>' . $targetName . '</info><fg=gray>:</> <comment>' . $deployId . '</comment>');
            } else {
                $io->writeln('<fg=gray>Applying latest staged release on</> <info>' . $targetName . '</info>');
            }
        }

        $io->writeln('');
        $io->block(
            'pinroll:apply  →  ' . $targetName,
            'INFO',
            'fg=black;bg=cyan',
            ' ',
            true,
        );

        $channel = $this->applyChannel($resolved, $raw);
        $io->definitionList(
            ['Target' => '<info>' . $targetName . '</info>'],
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
            $session = RolloutSession::create(Pinroll::config(), $targetName, 'apply', (string) ($resolved['transport'] ?? 'remote'));
            (new ReleaseApplier())->applyOnTarget($resolved, $raw, $deployId, $session);

            $result = array_merge($session->toArray(), [
                'deploy_id' => $deployId !== '' ? $deployId : ($session->toArray()['deploy_id'] ?? ''),
            ]);
            PinrollCli::printApplyResult($io, $result);

            return Command::SUCCESS;
        } finally {
            PushProgress::bind(null);
        }
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $resolved
     */
    private function applyChannel(array $resolved, array $raw): string
    {
        if ((string) ($resolved['transport'] ?? '') === 'local') {
            return 'local';
        }

        if (TargetGate::isConfigured($raw)) {
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
    private function resolveRemoteLatestId(array $raw, array $resolved): ?string
    {
        $gate = TargetGate::credentials($raw);
        $gateUrl = $gate['url'] !== '' ? $gate['url'] : (string) ($resolved['gate_url'] ?? '');
        $token = $gate['token'] !== '' ? $gate['token'] : (string) ($resolved['token'] ?? '');

        if ($gateUrl === '' || $token === '') {
            return null;
        }

        try {
            $incoming = PinGateClient::incoming($gateUrl, $token);
            $releases = is_array($incoming['releases'] ?? null) ? $incoming['releases'] : [];
            $first = $releases[0] ?? null;

            return is_array($first) ? (string) ($first['id'] ?? '') ?: null : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function printLocalIncomingList(SymfonyStyle $io): void
    {
        $incoming = Pinroll::config()->storage((string) Pinroll::config()->get('incoming_path', 'pinroll/incoming'));
        $this->printIncomingTable($io, IncomingRelease::list($incoming), 'local incoming/');
        $io->writeln('<fg=gray>Apply local:</> <comment>php pinoox pinroll:apply --local</comment>');
        $io->writeln('<fg=gray>Apply local id:</> <comment>php pinoox pinroll:apply --local {deploy_id}</comment>');
    }

    private function printRemoteIncomingList(SymfonyStyle $io, string $targetName, ?string $via): void
    {
        $resolved = Pinroll::targets()->resolve($targetName, $via);
        $raw = Pinroll::targets()->raw($targetName);
        $gate = TargetGate::credentials($raw);
        $gateUrl = $gate['url'] !== '' ? $gate['url'] : (string) ($resolved['gate_url'] ?? '');
        $token = $gate['token'] !== '' ? $gate['token'] : (string) ($resolved['token'] ?? '');

        if ($gateUrl === '' || $token === '') {
            throw new PinrollException(implode("\n", TargetGate::setupGuide($targetName)));
        }

        try {
            $incoming = PinGateClient::incoming($gateUrl, $token);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (str_contains(strtolower($message), 'unknown pingate route') || str_contains($message, 'incoming')) {
                throw new PinrollException(
                    "PinGate on the host is outdated (no /incoming).\n"
                    . "Re-upload: php pinoox pinroll:gate {$targetName}\n"
                    . "Then apply without --list: php pinoox pinroll:apply {$targetName}",
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

        $this->printIncomingTable($io, $releases, 'target ' . $targetName . ' (PinGate)');
        $io->writeln('<fg=gray>Apply latest:</> <comment>php pinoox pinroll:apply ' . $targetName . '</comment>');
        $io->writeln('<fg=gray>Apply specific:</> <comment>php pinoox pinroll:apply ' . $targetName . ' {deploy_id}</comment>');
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
