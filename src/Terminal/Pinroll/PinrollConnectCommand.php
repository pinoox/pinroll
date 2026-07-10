<?php

namespace Pinoox\Terminal\Pinroll;

use Pinoox\Component\Terminal;
use Pinoox\Pinroll\Console\ConnectService;
use Pinoox\Pinroll\Console\PinrollCli;
use Pinoox\Pinroll\Host\HostSelector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pinroll:connect',
    description: 'Connect host: deploy path, site URL, PinGate setup (transport-aware)',
)]
class PinrollConnectCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->addArgument('host', InputArgument::OPTIONAL, 'Host name')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host override')
            ->addOption('via', null, InputOption::VALUE_REQUIRED, 'Transport: ftp, ssh, pinion')
            ->addOption('bootstrap-ftp', null, InputOption::VALUE_NONE, 'Upload gate via FTP once, then set via=pinion')
            ->addOption('reset', null, InputOption::VALUE_NONE, 'Re-run deploy path, site URL, and PinGate setup');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);
        $root = defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd();

        try {
            $hostName = HostSelector::resolve($input, (string) ($input->getArgument('host') ?? ''));
            $via = (string) ($input->getOption('via') ?: '');
            $result = (new ConnectService((string) $root))->run(
                $io,
                $hostName,
                $via !== '' ? $via : null,
                (bool) $input->getOption('bootstrap-ftp'),
                (bool) $input->getOption('reset'),
            );

            if (($result['mode'] ?? '') === 'verified') {
                PinrollCli::printConnectStatus($io, $result);
            } else {
                PinrollCli::printConnectResult($io, $result);
            }

            return (($result['mode'] ?? '') === 'verified' && !($result['check']['ok'] ?? false))
                ? Command::FAILURE
                : Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
