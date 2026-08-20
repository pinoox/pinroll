<?php

namespace Pinoox\Terminal\Pinroll;

use Pinoox\Component\Terminal;
use Pinoox\Pinroll\Console\PathSyncer;
use Pinoox\Pinroll\Console\PinrollCli;
use Pinoox\Pinroll\Console\PinrollInput;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\PushConsole;
use Pinoox\Pinroll\Support\PushProgress;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pinroll:sync',
    description: 'FTP-sync a local folder to a path on the host (relative to deploy root)',
    aliases: ['pinroll:push:path', 'pinroll:path:sync'],
)]
class PinrollSyncCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->addArgument('host', InputArgument::OPTIONAL, 'Host name (omit when default_host is set)')
            ->addOption('from', 'f', InputOption::VALUE_REQUIRED, 'Local directory to upload')
            ->addOption('to', 't', InputOption::VALUE_REQUIRED, 'Remote path relative to deploy root (e.g. vendor/pinoox/pincore)')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host override')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be uploaded without sending files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);

        PushProgress::bind(
            static fn (string $message, string $style = PushConsole::STYLE_DEFAULT) => $io->writeln(PushConsole::format($message, $style)),
            $output->isVerbose(),
        );

        try {
            $from = trim((string) ($input->getOption('from') ?: ''));
            $to = trim((string) ($input->getOption('to') ?: ''));

            if ($from === '' || $to === '') {
                $io->error('Both --from and --to are required.' . "\n"
                    . 'Example: php pinoox pinroll:sync --from=./pincore --to=vendor/pinoox/pincore');

                return Command::FAILURE;
            }

            $root = defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd();
            Pinroll::boot(new NativePathResolver((string) $root));

            $hostName = PinrollInput::hostName($input);
            $dryRun = (bool) $input->getOption('dry-run');

            $io->block('pinroll:sync  →  ' . $hostName, 'INFO', 'fg=black;bg=cyan', ' ', true);

            $result = (new PathSyncer())->sync($hostName, $from, $to, (string) $root, $dryRun);

            $io->definitionList(
                ['Mode' => $dryRun ? '<comment>dry-run</comment>' : '<fg=green>upload</>'],
                ['Local' => '<comment>' . PinrollCli::relPath($result['local']) . '</comment>'],
                ['Remote' => '<comment>' . $result['remote'] . '</comment>'],
                ['Files' => '<info>' . $result['files'] . '</info>'],
            );

            $io->success($dryRun ? 'Dry-run complete — re-run without --dry-run to upload.' : 'Path synced to host.');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } finally {
            PushProgress::bind(null);
        }
    }
}
