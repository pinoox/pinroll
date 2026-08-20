<?php

namespace Pinoox\Terminal\Pinroll;

use Pinoox\Component\Terminal;
use Pinoox\Pinroll\Console\PathSyncer;
use Pinoox\Pinroll\Console\PinrollCli;
use Pinoox\Pinroll\Console\PinrollInput;
use Pinoox\Pinroll\Console\SetupRunner;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\PincorePaths;
use Pinoox\Pinroll\Support\PushProgress;
use Pinoox\Pinroll\Support\PushProgressBar;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pinroll:pincore',
    description: 'Zip + upload pincore, extract on host via PinGate (ftp / ssh / pinion)',
    aliases: ['pinroll:sync:pincore', 'pinroll:update:pincore'],
)]
class PinrollPincoreCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->addArgument('host', InputArgument::OPTIONAL, 'Host name (omit when default_host is set)')
            ->addOption('from', 'f', InputOption::VALUE_REQUIRED, 'Local pincore directory (default: vendor/pinoox/pincore or pincore/)')
            ->addOption('to', 't', InputOption::VALUE_REQUIRED, 'Remote path (default: vendor/pinoox/pincore)')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host override')
            ->addOption('via', null, InputOption::VALUE_REQUIRED, 'Transport override: ftp, ssh, pinion')
            ->addOption('setup', null, InputOption::VALUE_NONE, 'Run platform migrate after sync (pinroll:setup --migrate)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview file count without uploading');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);
        PushProgressBar::bind($output, $io, $output->isVerbose());

        try {
            $root = defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd();
            Pinroll::boot(new NativePathResolver((string) $root));

            $hostName = PinrollInput::hostName($input);
            $fromOption = trim((string) ($input->getOption('from') ?: ''));
            $toOption = trim((string) ($input->getOption('to') ?: ''));
            $via = trim((string) ($input->getOption('via') ?: ''));
            $dryRun = (bool) $input->getOption('dry-run');

            $local = PincorePaths::resolveLocal((string) $root, $fromOption !== '' ? $fromOption : null);
            $remote = $toOption !== '' ? $toOption : PincorePaths::REMOTE_VENDOR_PINCORE;

            $io->block('pinroll:pincore  →  ' . $hostName, 'INFO', 'fg=black;bg=cyan', ' ', true);
            $io->definitionList(
                ['Local' => '<comment>' . PinrollCli::relPath($local) . '</comment>'],
                ['Remote' => '<comment>' . $remote . '</comment>'],
                ['Mode' => '<comment>zip → upload → PinGate extract</comment>'],
            );

            $result = (new PathSyncer())->sync(
                $hostName,
                $local,
                $remote,
                (string) $root,
                $dryRun,
                $via !== '' ? $via : null,
            );

            $io->writeln('  <fg=gray>Files</>      <info>' . $result['files'] . '</info>');
            if (!empty($result['transport'])) {
                $io->writeln('  <fg=gray>Transport</>  <comment>' . $result['transport'] . '</comment>');
            }

            if ($dryRun) {
                $io->success('Dry-run complete — re-run without --dry-run to upload pincore.');

                return Command::SUCCESS;
            }

            $io->success('Pincore synced to host (zip + extract).');
            $io->writeln('  <fg=gray>Tip:</> host needs updated pingate.php — deploy auto-syncs it; or run <comment>pinroll:gate</comment>.');

            if ($input->getOption('setup')) {
                $io->section('Platform migrate');
                (new SetupRunner((string) $root))->run($io, $output, [
                    'host' => $hostName,
                    'migrate' => true,
                    'patch' => false,
                ]);
            } else {
                $io->writeln('  Next: <comment>php pinoox pinroll:setup --migrate</comment> if this release has DB migrations.');
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } finally {
            PushProgress::endBar();
            PushProgress::bind(null);
        }
    }
}
