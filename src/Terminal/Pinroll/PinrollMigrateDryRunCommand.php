<?php

namespace Pinoox\Terminal\Pinroll;

use Pinoox\Component\Terminal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'pinroll:migrate:dry-run', description: 'Show pending migrations for the next rollout without applying them')]
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

        $root = defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd();
        $pinoox = is_file($root . '/pinoox') ? $root . '/pinoox' : 'pinoox';
        $pending = [];

        if ($input->getOption('platform')) {
            $pending['platform'] = $this->migrationStatus($pinoox, 'platform');
        }

        $package = $input->getOption('package');
        if ($package) {
            $pending[(string) $package] = $this->migrationStatus($pinoox, (string) $package);
        }

        $io->writeln(json_encode(['pending' => $pending], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $io->note('Dry-run only lists migration status; Pinroll applies migrations during rollout.');

        return Command::SUCCESS;
    }

  /**
     * @return array{output: list<string>, exit_code: int}
     */
    private function migrationStatus(string $pinoox, string $package): array
    {
        $cmd = 'php ' . escapeshellarg($pinoox) . ' migrate:status ' . escapeshellarg($package) . ' 2>&1';
        exec($cmd, $output, $code);

        return ['output' => $output, 'exit_code' => $code];
    }
}
