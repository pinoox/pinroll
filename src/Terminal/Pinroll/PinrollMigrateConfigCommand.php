<?php

namespace Pinoox\Terminal\Pinroll;

use Pinoox\Component\Terminal;
use Pinoox\Pinroll\Console\ConfigTemplate;
use Pinoox\Pinroll\Console\ConfigWriter;
use Pinoox\Pinroll\Console\SampleConfig;
use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Host\HostConfig;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\ConfigFileLoader;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\ProjectPaths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pinroll:migrate-config',
    description: 'Rewrite legacy pinroll.config.php (targets → hosts, dir → deploy_path)',
)]
class PinrollMigrateConfigCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show changes without writing')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite even when already migrated');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);

        try {
            $root = defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd();
            Pinroll::boot(new NativePathResolver((string) $root));

            $path = ProjectPaths::configFile(Pinroll::paths());
            if ($path === null || !is_file($path)) {
                throw new PinrollException('pinroll/pinroll.config.php not found. Run pinroll:init first.');
            }

            /** @var array<string, mixed> $loaded */
            $loaded = ConfigFileLoader::load($path);
            $needsMigration = isset($loaded['targets']) || $this->hostsNeedMigration(HostConfig::hostBlocks($loaded));

            if (!$needsMigration && !$input->getOption('force')) {
                $io->success('Config already uses hosts/deploy_path — nothing to migrate.');

                return Command::SUCCESS;
            }

            $migrated = $this->migrate($loaded);
            $rendered = ConfigTemplate::renderHosts(
                $migrated['hosts'],
                $migrated['globals'],
            );

            if ($input->getOption('dry-run')) {
                $io->section('Migrated config (dry-run)');
                $io->writeln($rendered);

                return Command::SUCCESS;
            }

            $backup = $path . '.bak.' . date('YmdHis');
            if (!copy($path, $backup)) {
                throw new PinrollException('Unable to create backup: ' . $backup);
            }

            if (file_put_contents($path, $rendered) === false) {
                throw new PinrollException('Unable to write migrated config.');
            }

            $io->success('Migrated pinroll.config.php');
            $io->writeln('<fg=gray>Backup: ' . $backup . '</>');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * @param array<string, mixed> $loaded
     * @return array{hosts: array<string, array<string, mixed>>, globals: array<string, mixed>}
     */
    private function migrate(array $loaded): array
    {
        $loaded = HostConfig::normalizeLoaded($loaded);
        $hosts = HostConfig::hostBlocks($loaded);
        $globals = array_merge(SampleConfig::globalDefaults(), [
            'default_host' => $loaded['default_host'] ?? SampleConfig::globalDefaults()['default_host'],
            'keep' => $loaded['keep'] ?? SampleConfig::globalDefaults()['keep'],
            'store' => $loaded['store'] ?? SampleConfig::globalDefaults()['store'],
            'auto_clean' => $loaded['auto_clean'] ?? SampleConfig::globalDefaults()['auto_clean'],
        ]);

        $migratedHosts = [];
        foreach ($hosts as $name => $host) {
            if (!is_array($host)) {
                continue;
            }

            if (!isset($host['deploy_path']) && isset($host['dir'])) {
                $host['deploy_path'] = $host['dir'];
                unset($host['dir']);
            }

            if (isset($host['package']) && !isset($host['apps'])) {
                $package = $host['package'];
                if (is_string($package) && $package !== '') {
                    $host['apps'] = [$package];
                }
                unset($host['package']);
            }

            $migratedHosts[(string) $name] = $host;
        }

        if ($globals['default_host'] === 'production' && !isset($migratedHosts['production']) && $migratedHosts !== []) {
            $globals['default_host'] = (string) array_key_first($migratedHosts);
        }

        return ['hosts' => $migratedHosts, 'globals' => $globals];
    }

    /**
     * @param array<string, array<string, mixed>> $hosts
     */
    private function hostsNeedMigration(array $hosts): bool
    {
        foreach ($hosts as $host) {
            if (isset($host['dir']) || isset($host['package'])) {
                return true;
            }
        }

        return false;
    }
}
