<?php

namespace Pinoox\Terminal\Pinroll;

use Pinoox\Component\Terminal;
use Pinoox\Pinroll\Console\SetupRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pinroll:migrate-config',
    description: 'Deprecated — use pinroll:setup --config',
)]
class PinrollMigrateConfigCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show changes without writing')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite even when already migrated');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);
        $io->warning('pinroll:migrate-config is deprecated. Use: php pinoox pinroll:setup --config');

        $root = defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd();

        try {
            $result = (new SetupRunner((string) $root, $this->getApplication()))->run($io, $output, [
                'config' => true,
                'dry_run' => (bool) $input->getOption('dry-run'),
                'force' => (bool) $input->getOption('force'),
            ]);

            return !empty($result['ok']) ? Command::SUCCESS : Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
