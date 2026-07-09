<?php

namespace Pinoox\Terminal\Pinroll;

use Pinoox\Component\Terminal;
use Pinoox\Pinroll\Market\ManifestPoller;
use Pinoox\Pinroll\Market\ReleaseServer;
use Pinoox\Pinroll\Release\ReleaseManifest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'pinroll:publish', description: 'Publish a release manifest to a remote channel')]
class PinrollPublishCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->addOption('server', null, InputOption::VALUE_REQUIRED, 'Release server base URL')
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Release channel', 'stable')
            ->addOption('manifest', null, InputOption::VALUE_REQUIRED, 'Path to manifest.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);

        $serverUrl = (string) $input->getOption('server');
        $manifestPath = (string) $input->getOption('manifest');

        if ($serverUrl === '' || $manifestPath === '') {
            $io->error('Both --server and --manifest are required.');

            return Command::FAILURE;
        }

        try {
            $manifest = ReleaseManifest::fromJsonFile($manifestPath);
            $result = (new ReleaseServer($serverUrl))->publish($manifest, (string) $input->getOption('channel'));
            $io->success('Published to channel ' . $input->getOption('channel'));
            $io->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
