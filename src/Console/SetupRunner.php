<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\PinrollAutoloader;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Host/local post-deploy setup: migrations, seeders, patches, optional config rewrite.
 */
final class SetupRunner
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly ?Application $application = null,
    ) {
        PinrollAutoloader::register($this->projectRoot);
        Pinroll::boot(new NativePathResolver($this->projectRoot));
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function run(SymfonyStyle $io, OutputInterface $output, array $options = []): array
    {
        $steps = SetupPlan::steps($options);
        $host = [];
        $hostName = trim((string) ($options['host'] ?? ''));
        if ($hostName !== '') {
            try {
                $host = Pinroll::hosts()->raw($hostName);
            } catch (\Throwable) {
                $host = [];
            }
        }

        $packages = SetupPlan::packages($options, $host, $this->projectRoot);
        $dryRun = !empty($options['dry_run']);
        $force = !empty($options['force']);
        $class = isset($options['class']) && is_string($options['class']) && $options['class'] !== ''
            ? (string) $options['class']
            : null;

        $io->definitionList(
            ['Steps' => implode(' → ', $steps)],
            ['Packages' => $packages !== [] ? implode(', ', $packages) : '—'],
            ['Mode' => $dryRun ? 'dry-run' : 'apply'],
        );
        $io->newLine();

        $results = [];
        $failed = false;

        foreach ($steps as $step) {
            if ($step === 'config') {
                $results['config'] = $this->runConfig($io, $dryRun, $force);
                continue;
            }

            if ($packages === []) {
                throw new PinrollException(
                    'No packages to set up. Pass --app= / --apps= or configure hosts.*.apps.',
                );
            }

            foreach ($packages as $package) {
                $key = $step . ':' . $package;
                $code = $this->runPackageStep($output, $step, $package, $dryRun, $force, $class);
                $results[$key] = $code;
                if ($code !== 0) {
                    $failed = true;
                    $io->error($step . ' failed for ' . $package);
                    if (!$force) {
                        break 2;
                    }
                }
            }
        }

        return [
            'steps' => $steps,
            'packages' => $packages,
            'dry_run' => $dryRun,
            'results' => $results,
            'ok' => !$failed,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runConfig(SymfonyStyle $io, bool $dryRun, bool $force): array
    {
        $result = (new ConfigMigrator())->run($this->projectRoot, $dryRun, $force);

        if (!$result['needed'] && !$force) {
            $io->success('Pinroll config already uses hosts/deploy_path — nothing to migrate.');

            return $result;
        }

        if ($dryRun) {
            $io->section('Migrated pinroll.config.php (dry-run)');
            $io->writeln($result['rendered']);

            return $result;
        }

        $io->success('Migrated pinroll.config.php');
        if (!empty($result['backup'])) {
            $io->writeln('<fg=gray>Backup: ' . $result['backup'] . '</>');
        }

        return $result;
    }

    private function runPackageStep(
        OutputInterface $output,
        string $step,
        string $package,
        bool $dryRun,
        bool $force,
        ?string $class,
    ): int {
        if ($dryRun && $step === 'seed') {
            $output->writeln('<comment>Seed has no dry-run; skip ' . $package . ' (pass without --dry-run to seed).</comment>');

            return 0;
        }

        $command = match (true) {
            $step === 'migrate' && $dryRun => 'migrate:status',
            $step === 'migrate' => 'migrate',
            $step === 'patch' && $dryRun => 'patch:status',
            $step === 'patch' => 'patch:run',
            $step === 'seed' => 'seeder:run',
            default => throw new PinrollException('Unknown setup step: ' . $step),
        };

        $args = ['package' => $package];
        if ($force && in_array($command, ['migrate', 'patch:run', 'seeder:run'], true)) {
            $args['--force'] = true;
        }
        if ($class !== null && in_array($command, ['patch:run', 'seeder:run'], true)) {
            $args['--class'] = $class;
        }

        return $this->runPincore($command, $args, $output);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function runPincore(string $commandName, array $arguments, OutputInterface $output): int
    {
        if ($this->application !== null) {
            $command = $this->application->find($commandName);
            $input = new ArrayInput($arguments);
            $input->setInteractive(false);

            return $command->run($input, $output);
        }

        $root = rtrim(str_replace('\\', '/', $this->projectRoot), '/');
        $pinoox = is_file($root . '/pinoox') ? $root . '/pinoox' : 'pinoox';
        $parts = ['php', escapeshellarg($pinoox), escapeshellarg($commandName)];
        foreach ($arguments as $key => $value) {
            if ($key === 'package') {
                $parts[] = escapeshellarg((string) $value);
                continue;
            }
            if ($value === true) {
                $parts[] = escapeshellarg((string) $key);
                continue;
            }
            if ($value === false || $value === null || $value === '') {
                continue;
            }
            $parts[] = escapeshellarg((string) $key . '=' . $value);
        }
        $parts[] = '-n';

        exec(implode(' ', $parts) . ' 2>&1', $lines, $code);
        foreach ($lines as $line) {
            $output->writeln((string) $line);
        }

        return $code;
    }
}
