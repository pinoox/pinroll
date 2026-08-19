<?php

namespace Pinoox\Terminal\Pinroll;

use Pinoox\Component\Terminal;
use Pinoox\Pinroll\Console\PinrollCli;
use Pinoox\Pinroll\Console\PinrollInput;
use Pinoox\Pinroll\Host\ResolvedConfig;
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
    name: 'pinroll:config',
    description: 'Show resolved host config (token redacted)',
)]
class PinrollConfigCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->addArgument('host', InputArgument::OPTIONAL, 'Host name (omit for all / default)')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host override')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);

        try {
            $root = defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd();
            Pinroll::boot(new NativePathResolver((string) $root));

            $host = PinrollInput::resolveOptionalHost($input);
            $rows = $host !== null
                ? [ResolvedConfig::forHost($host)]
                : ResolvedConfig::all();

            if ($input->getOption('json')) {
                $io->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                return Command::SUCCESS;
            }

            $io->writeln('<info>pinroll:config</info>  <fg=gray>library → overlay → env</>');
            $io->newLine();

            foreach ($rows as $row) {
                PinrollCli::printResolvedConfig($io, $row);
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
