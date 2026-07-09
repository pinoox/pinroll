<?php

namespace Pinoox\Terminal\Pinroll;

use Pinoox\Component\Terminal;
use Pinoox\Pinroll\Market\ManifestPoller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pinroll:pull',
    description: 'Pull newer release manifest from a release server',
    aliases: ['pinroll:poll'],
)]
class PinrollPullCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->addOption('server', null, InputOption::VALUE_REQUIRED, 'Release server base URL')
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Release channel', 'stable')
            ->addOption('current', null, InputOption::VALUE_REQUIRED, 'Current installed version');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);

        $serverUrl = (string) $input->getOption('server');
        if ($serverUrl === '') {
            $io->error('--server is required.');

            return Command::FAILURE;
        }

        $manifest = (new ManifestPoller($serverUrl))->poll(
            (string) $input->getOption('channel'),
            $input->getOption('current') ? (string) $input->getOption('current') : null,
        );

        if ($manifest === null) {
            $io->warning('No update available or server unreachable.');

            return Command::SUCCESS;
        }

        $io->success('Update available.');
        $io->writeln(json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return Command::SUCCESS;
    }
}
