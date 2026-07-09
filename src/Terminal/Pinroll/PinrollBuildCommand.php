<?php

namespace Pinoox\Terminal\Pinroll;

use Pinoox\Component\Terminal;
use Pinoox\Pinroll\Console\DeployRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'pinroll:build', description: 'Build a release bundle without deploying')]
class PinrollBuildCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->addOption('bundle', null, InputOption::VALUE_REQUIRED, 'Bundle recipe', 'single-app')
            ->addOption('package', null, InputOption::VALUE_REQUIRED, 'App package');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);

        try {
            $result = (new DeployRunner())->build(
                (string) $input->getOption('bundle'),
                $input->getOption('package') ? (string) $input->getOption('package') : null,
            );
            $io->success('Build complete: ' . $result['archive']);
            $io->writeln(json_encode($result['manifest'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
