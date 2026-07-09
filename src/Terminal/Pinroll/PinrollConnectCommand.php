<?php

namespace Pinoox\Terminal\Pinroll;

use Pinoox\Component\Terminal;
use Pinoox\Pinroll\Console\ConnectService;
use Pinoox\Pinroll\Console\PinrollCli;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pinroll:connect',
    description: 'Connect host: set deploy path + site URL, upload PinGate via FTP',
)]
class PinrollConnectCommand extends Terminal
{
    protected function configure(): void
    {
        $this->addArgument('target', InputArgument::OPTIONAL, 'Target name', 'production');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);
        $root = defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd();
        $target = (string) ($input->getArgument('target') ?: 'production');

        try {
            $result = (new ConnectService((string) $root))->run($io, $target);
            PinrollCli::printConnectResult($io, $result);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
