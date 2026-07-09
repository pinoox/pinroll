<?php

namespace Pinoox\Terminal\Pinroll;

use Pinoox\Component\Terminal;
use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\StorageCleaner;
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
    name: 'pinroll:cleanup',
    description: 'Prune old Pinroll archives/tmp on a target (PinGate) or locally',
    aliases: ['pinroll:prune'],
)]
class PinrollCleanupCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->addArgument('target', InputArgument::OPTIONAL, 'Target name (omit with --local)', 'production')
            ->addOption('keep', 'k', InputOption::VALUE_REQUIRED, 'Keep N newest incoming/releases/sessions', '3')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be deleted without deleting')
            ->addOption('local', null, InputOption::VALUE_NONE, 'Clean this machine only (not remote host)')
            ->addOption('incoming-only', null, InputOption::VALUE_NONE, 'Only prune storage/pinroll/incoming')
            ->addOption('via', null, InputOption::VALUE_REQUIRED, 'Transport override');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);

        try {
            $root = defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd();
            Pinroll::configure([], new NativePathResolver((string) $root));

            $keep = max(0, (int) $input->getOption('keep'));
            $dryRun = (bool) $input->getOption('dry-run');
            $local = (bool) $input->getOption('local');
            $incomingOnly = (bool) $input->getOption('incoming-only');
            $target = (string) $input->getArgument('target');
            $via = (string) ($input->getOption('via') ?: '');

            $options = [
                'keep' => $keep,
                'dry_run' => $dryRun,
                'incoming' => true,
                'tmp' => !$incomingOnly,
                'staging' => !$incomingOnly,
                'sessions' => !$incomingOnly,
                'releases' => !$incomingOnly,
                'backups' => !$incomingOnly,
            ];

            $io->writeln('');
            $io->block(
                'pinroll:cleanup  →  ' . ($local ? 'local' : $target),
                'INFO',
                'fg=black;bg=cyan',
                ' ',
                true,
            );

            $result = $local
                ? $this->cleanLocal($options)
                : $this->cleanRemote($target, $via !== '' ? $via : null, $options);

            $this->printResult($io, $result);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function cleanLocal(array $options): array
    {
        return (new StorageCleaner(Pinroll::config()))->clean($options);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function cleanRemote(string $targetName, ?string $via, array $options): array
    {
        $resolved = Pinroll::targets()->resolve($targetName, $via);
        $raw = Pinroll::targets()->raw($targetName);
        $gate = TargetGate::credentials($raw);
        $gateUrl = $gate['url'] !== '' ? $gate['url'] : (string) ($resolved['gate_url'] ?? '');
        $token = $gate['token'] !== '' ? $gate['token'] : (string) ($resolved['token'] ?? '');

        if ($gateUrl === '' || $token === '') {
            throw new PinrollException(implode("\n", TargetGate::setupGuide($targetName)));
        }

        return PinGateClient::cleanup($gateUrl, $token, $options);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function printResult(SymfonyStyle $io, array $result): void
    {
        $dryRun = !empty($result['dry_run']);
        $deleted = is_array($result['deleted'] ?? null) ? $result['deleted'] : [];
        $kept = is_array($result['kept'] ?? null) ? $result['kept'] : [];
        $bytes = (int) ($result['bytes_freed'] ?? 0);
        $keep = (int) ($result['keep'] ?? 0);

        $io->definitionList(
            ['Mode' => $dryRun ? '<comment>dry-run</comment>' : '<fg=green>delete</>'],
            ['Keep' => '<info>' . $keep . '</info> newest'],
            ['Removed' => '<info>' . count($deleted) . '</info> items'],
            ['Freed' => '<comment>' . $this->formatBytes($bytes) . '</comment>'],
            ['Kept' => '<fg=gray>' . count($kept) . ' items</>'],
        );

        if ($deleted === []) {
            $io->success($dryRun ? 'Nothing to prune.' : 'Already clean.');

            return;
        }

        $io->section($dryRun ? 'Would delete' : 'Deleted');
        foreach (array_slice($deleted, 0, 40) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $io->writeln(sprintf(
                '  <fg=red>-</> %s  <fg=gray>(%s · %s)</>',
                (string) ($row['path'] ?? ''),
                $this->formatBytes((int) ($row['bytes'] ?? 0)),
                (string) ($row['reason'] ?? ''),
            ));
        }

        if (count($deleted) > 40) {
            $io->writeln('  <fg=gray>… and ' . (count($deleted) - 40) . ' more</>');
        }

        $io->success($dryRun
            ? 'Dry-run complete — re-run without --dry-run to delete.'
            : 'Cleanup complete.');
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / (1024 * 1024), 1) . ' MB';
    }
}
