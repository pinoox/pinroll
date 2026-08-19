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
    name: 'pinroll:migrate:dry-run',
    description: 'Deprecated — use pinroll:setup --migrate --dry-run',
)]
class PinrollMigrateDryRunCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->addOption('package', null, InputOption::VALUE_REQUIRED, 'App package')
            ->addOption('platform', null, InputOption::VALUE_NONE, 'Include platform migrations');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);
        $io->warning('pinroll:migrate:dry-run is deprecated. Use: php pinoox pinroll:setup --migrate --dry-run');

        $root = defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd();
        $package = $input->getOption('package');
        $skipPlatform = !$input->getOption('platform') && (is_string($package) && $package !== '');

        try {
            $result = (new SetupRunner((string) $root, $this->getApplication()))->run($io, $output, [
                'migrate' => true,
                'dry_run' => true,
                'package' => is_string($package) ? $package : null,
                'skip_platform' => $skipPlatform,
            ]);

            return !empty($result['ok']) ? Command::SUCCESS : Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
