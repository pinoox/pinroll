<?php

namespace Pinoox\Terminal\Pinroll;

use Pinoox\Component\Terminal;
use Pinoox\Pinroll\Console\InitService;
use Pinoox\Pinroll\Console\PinrollCli;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pinroll:init',
    description: 'Initialize pinroll config and bundles',
)]
class PinrollInitCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->addArgument('target', InputArgument::OPTIONAL, 'Target name', 'production')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Rewrite config and bundle stubs')
            ->addOption('wizard', 'w', InputOption::VALUE_NONE, 'Interactive connection setup');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);
        $root = defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd();
        $target = (string) ($input->getArgument('target') ?: 'production');

        try {
            $wizard = (bool) $input->getOption('wizard');
            $result = (new InitService((string) $root))->run(
                $target,
                $input->isInteractive() && !(bool) $input->getOption('no-interaction'),
                (bool) $input->getOption('force'),
                $io,
                $wizard,
            );

            PinrollCli::printInitSummary($io, $result);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
