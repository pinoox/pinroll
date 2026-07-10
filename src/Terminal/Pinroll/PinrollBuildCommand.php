<?php

namespace Pinoox\Terminal\Pinroll;

use Pinoox\Component\Terminal;
use Pinoox\Pinroll\Console\DeployRunner;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Release\PlatformProfile;
use Pinoox\Pinroll\Support\NativePathResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'pinroll:build', description: 'Build a release from platform apps (auto-detected)')]
class PinrollBuildCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->addOption('app', null, InputOption::VALUE_REQUIRED, 'App package to build')
            ->addOption('package', null, InputOption::VALUE_REQUIRED, 'Deprecated — use --app')
            ->addOption('bundle', null, InputOption::VALUE_REQUIRED, 'Optional custom recipe in pinroll/bundles/ or builtin name (platform-full, platform-core)')
            ->addOption('platform', null, InputOption::VALUE_NONE, 'Build full platform (core + all apps in apps/)')
            ->addOption('core', null, InputOption::VALUE_NONE, 'Build platform core only');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);

        try {
            $root = defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd();
            Pinroll::boot(new NativePathResolver((string) $root));

            $profile = PlatformProfile::fromRoot((string) $root);
            $app = (string) ($input->getOption('app') ?: $input->getOption('package') ?: '');
            $bundle = (string) ($input->getOption('bundle') ?: '');

            if ($input->getOption('platform')) {
                $bundle = 'platform-full';
            } elseif ($input->getOption('core')) {
                $bundle = 'platform-core';
            }

            $io->writeln(sprintf(
                '<info>Platform layout:</info> %s (%d package%s)',
                $profile->layout(),
                count($profile->packages()),
                count($profile->packages()) === 1 ? '' : 's',
            ));

            $result = (new DeployRunner((string) $root))->build(
                $bundle !== '' ? $bundle : null,
                $app !== '' ? $app : null,
            );

            $io->success('Build complete: ' . $result['archive']);
            $io->writeln(json_encode($result['manifest'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            $io->writeln('<fg=gray>Tip: pass --bundle=platform-full, --platform, or --app=com_package for explicit control.</>');

            return Command::FAILURE;
        }
    }
}
