<?php

namespace Pinoox\Terminal\Pinroll;

use Pinoox\Component\Terminal;
use Pinoox\Pinroll\Console\PinrollCli;
use Pinoox\Pinroll\Console\PinrollInput;
use Pinoox\Pinroll\Host\HostChecker;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\NativePathResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pinroll:check',
    description: 'Check configured hosts',
)]
class PinrollCheckCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->addArgument('host', InputArgument::OPTIONAL, 'Host name (omit for all)')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host override')
            ->addOption('via', null, InputOption::VALUE_REQUIRED, 'Check a specific transport: ftp, ssh, pinion')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);

        try {
            $root = defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd();
            Pinroll::boot(new NativePathResolver((string) $root));

            $checker = new HostChecker();
            $host = PinrollInput::resolveOptionalHost($input);
            $via = (string) ($input->getOption('via') ?: '');

            $results = $host !== null
                ? [$checker->check($host, $via !== '' ? $via : null)]
                : $checker->checkAll();

            if ($input->getOption('json')) {
                $io->writeln(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                return $this->exitCode($results);
            }

            $io->writeln('<info>pinroll:check</info>');
            $io->newLine();

            foreach ($results as $result) {
                PinrollCli::printCheckResult($io, $result);
            }

            return $this->exitCode($results);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * @param list<array<string, mixed>> $results
     */
    private function exitCode(array $results): int
    {
        foreach ($results as $result) {
            if (!($result['ok'] ?? false)) {
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }
}
