<?php

namespace Pinoox\Terminal\Pinroll;

use Pinoox\Component\Terminal;
use Pinoox\Pinroll\Console\SetupRunner;
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
    name: 'pinroll:setup',
    description: 'Run post-deploy setup: migrate, patch, seed, and/or rewrite pinroll.config.php',
)]
class PinrollSetupCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->addArgument('host', InputArgument::OPTIONAL, 'Host name (uses hosts.*.apps when --app is omitted)')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host override')
            ->addOption('migrate', null, InputOption::VALUE_NONE, 'Run database migrations')
            ->addOption('patch', null, InputOption::VALUE_NONE, 'Run data patches')
            ->addOption('seed', null, InputOption::VALUE_NONE, 'Run database seeders')
            ->addOption('config', null, InputOption::VALUE_NONE, 'Rewrite legacy pinroll.config.php (targets → hosts)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show pending migrate/patch/config without applying')
            ->addOption('skip-platform', null, InputOption::VALUE_NONE, 'Do not run platform before apps')
            ->addOption('app', null, InputOption::VALUE_REQUIRED, 'App package')
            ->addOption('apps', null, InputOption::VALUE_REQUIRED, 'Comma-separated app packages')
            ->addOption('package', null, InputOption::VALUE_REQUIRED, 'Deprecated — use --app')
            ->addOption('class', 'c', InputOption::VALUE_REQUIRED, 'Specific seeder or patch class')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Continue after a failed step / overwrite migrated config')
            ->setHelp(
                <<<'HELP'
Default (no step flags): migrate + patch for platform and discovered apps.

Examples:
  php pinoox pinroll:setup
  php pinoox pinroll:setup --dry-run
  php pinoox pinroll:setup --migrate --patch --seed
  php pinoox pinroll:setup --config
  php pinoox pinroll:setup --app=com_acme_shop --migrate
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);
        $root = defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd();

        try {
            Pinroll::boot(new NativePathResolver((string) $root));
            $host = HostSelector::resolveOptional($input, (string) ($input->getArgument('host') ?? '')) ?? '';

            $result = (new SetupRunner((string) $root, $this->getApplication()))->run($io, $output, [
                'host' => $host,
                'migrate' => (bool) $input->getOption('migrate'),
                'patch' => (bool) $input->getOption('patch'),
                'seed' => (bool) $input->getOption('seed'),
                'config' => (bool) $input->getOption('config'),
                'dry_run' => (bool) $input->getOption('dry-run'),
                'skip_platform' => (bool) $input->getOption('skip-platform'),
                'app' => $input->getOption('app'),
                'apps' => $input->getOption('apps'),
                'package' => $input->getOption('package'),
                'class' => $input->getOption('class'),
                'force' => (bool) $input->getOption('force'),
            ]);

            if (!empty($result['ok'])) {
                $io->success($result['dry_run'] ? 'Setup dry-run complete.' : 'Setup complete.');
            }

            return !empty($result['ok']) ? Command::SUCCESS : Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
