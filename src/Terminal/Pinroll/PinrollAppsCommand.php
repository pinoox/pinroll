<?php

namespace Pinoox\Terminal\Pinroll;

use Pinoox\Component\Terminal;
use Pinoox\Pinroll\Console\AppPicker;
use Pinoox\Pinroll\Console\HostAppsWriter;
use Pinoox\Pinroll\Console\ProjectPackages;
use Pinoox\Pinroll\Host\HostSelector;
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
    name: 'pinroll:apps',
    description: 'Set or show default app packages for a host in pinroll.config.php',
)]
class PinrollAppsCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->addArgument('host', InputArgument::OPTIONAL, 'Host name (omit when default_host is set)')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host override')
            ->addOption('apps', null, InputOption::VALUE_REQUIRED, 'Comma-separated app packages')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Use all apps from apps/')
            ->addOption('clear', null, InputOption::VALUE_NONE, 'Remove apps from config (push will prompt again)')
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'Show configured apps without changing config');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);
        $root = defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd();

        try {
            Pinroll::boot(new NativePathResolver((string) $root));

            $hostName = HostSelector::resolve($input, (string) ($input->getArgument('host') ?? ''));
            $writer = new HostAppsWriter((string) $root);

            if ($input->getOption('list')) {
                $this->printApps($io, $hostName, $writer->read($hostName));

                return Command::SUCCESS;
            }

            if ($input->getOption('clear')) {
                $writer->write($hostName, null);
                $io->success('Cleared apps for host <info>' . $hostName . '</info>.');
                $io->writeln('  <fg=gray>pinroll:push will prompt for apps until you set them again.</>');

                return Command::SUCCESS;
            }

            $apps = $this->resolveApps($io, $input, (string) $root);
            if ($apps === []) {
                $io->warning('No apps selected.');

                return Command::FAILURE;
            }

            $writer->write($hostName, $apps);

            $io->success('Updated apps for host <info>' . $hostName . '</info>.');
            $io->writeln('  <fg=gray>Apps</>  <comment>' . implode(', ', $apps) . '</comment>');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * @return list<string>
     */
    private function resolveApps(SymfonyStyle $io, InputInterface $input, string $root): array
    {
        if ($input->getOption('all')) {
            $apps = ProjectPackages::list($root);
            if ($apps === []) {
                throw new \RuntimeException('No apps found in apps/.');
            }

            return $apps;
        }

        $appsOption = $input->getOption('apps');
        if (is_string($appsOption) && trim($appsOption) !== '') {
            return HostAppsWriter::parseList($appsOption);
        }

        return AppPicker::selectRequired($io, $root);
    }

    /**
     * @param list<string> $apps
     */
    private function printApps(SymfonyStyle $io, string $hostName, array $apps): void
    {
        $io->writeln('  <fg=gray>Host</>  <info>' . $hostName . '</info>');

        if ($apps === []) {
            $io->writeln('  <fg=gray>Apps</>  <fg=yellow>not set</> <fg=gray>(pinroll:push will prompt)</>');
            $available = ProjectPackages::list(defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd());
            if ($available !== []) {
                $io->writeln('  <fg=gray>Available</> ' . implode(', ', $available));
            }

            return;
        }

        $io->writeln('  <fg=gray>Apps</>  <comment>' . implode(', ', $apps) . '</comment>');
    }
}
