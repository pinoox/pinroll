<?php

namespace Pinoox\Terminal\Pinroll;

use Pinoox\Component\Terminal;
use Pinoox\Pinroll\Console\DeployRunner;
use Pinoox\Pinroll\Console\PinrollCli;
use Pinoox\Pinroll\Console\PushRuleResolver;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\PushConsole;
use Pinoox\Pinroll\Support\PushProgress;
use Pinoox\Pinroll\Target\TargetChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pinroll:deploy',
    description: 'Push release to a target and apply via PinGate (go live)',
)]
class PinrollDeployCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->addArgument('target', InputArgument::OPTIONAL, 'Target name (prod = production)', 'production')
            ->addOption('via', null, InputOption::VALUE_REQUIRED, 'Transport: ftp, ssh, pinion')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Push app + vendor + theme')
            ->addOption('app', null, InputOption::VALUE_NONE, 'Push app package(s)')
            ->addOption('vendor', null, InputOption::VALUE_NONE, 'Sync vendor/')
            ->addOption('theme', null, InputOption::VALUE_NONE, 'Sync theme dist/')
            ->addOption('package', null, InputOption::VALUE_REQUIRED, 'Single app package override')
            ->addOption('check', 'c', InputOption::VALUE_NONE, 'Run pinroll:check before deploying');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);
        $verbose = $output->isVerbose();
        $progressBar = null;

        PushProgress::bind(
            function (string $message, string $style = PushConsole::STYLE_DEFAULT) use ($io): void {
                $formatted = PushConsole::format($message, $style);
                if ($formatted === '') {
                    $io->newLine();

                    return;
                }

                $io->writeln($formatted);
            },
            $verbose,
            function (int $current, int $total, string $label) use ($output, $io, &$progressBar): void {
                if ($progressBar === null) {
                    $progressBar = new ProgressBar($output, max(1, $total));
                    $progressBar->setBarCharacter('▓');
                    $progressBar->setEmptyBarCharacter('░');
                    $progressBar->setProgressCharacter('');
                    $progressBar->setFormat(
                        ' <fg=cyan>%current%/%max%</> <fg=green>[%bar%]</> <fg=gray>%percent:3s%%</> <comment>%message%</>',
                    );
                    $progressBar->setMessage($label);
                    $progressBar->start();
                }

                $progressBar->setMessage($label);
                $progressBar->setProgress($current);

                if ($current >= $total) {
                    $progressBar->finish();
                    $io->newLine();
                    $progressBar = null;
                }
            },
        );

        try {
            $root = defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd();
            Pinroll::configure([], new NativePathResolver((string) $root));

            $targetName = (string) $input->getArgument('target');
            $via = (string) ($input->getOption('via') ?: '');
            $available = Pinroll::targets()->transports($targetName);
            $resolved = Pinroll::targets()->resolve($targetName, $via !== '' ? $via : null);

            $options = array_filter([
                'via' => $via !== '' ? $via : null,
                'all' => $input->getOption('all') ? true : null,
                'app' => $input->getOption('app') ? true : null,
                'vendor' => $input->getOption('vendor') ? true : null,
                'theme' => $input->getOption('theme') ? true : null,
                'package' => $input->getOption('package'),
                'apply' => true,
            ], static fn (mixed $value): bool => $value !== null && $value !== false && $value !== '');

            $plan = PushRuleResolver::resolve($resolved, $options);

            $this->printHeader($io, $targetName, $resolved['transport'], $available, $plan);

            if ($input->getOption('check')) {
                $check = (new TargetChecker())->check($targetName, $via !== '' ? $via : null);
                if (!($check['ok'] ?? false)) {
                    PinrollCli::printCheckResult($io, $check);

                    return Command::FAILURE;
                }
            }

            $result = (new DeployRunner())->deploy($targetName, $options);
            PinrollCli::printPushResult($io, $result);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } finally {
            PushProgress::bind(null);
        }
    }

    /**
     * @param list<string> $available
     * @param array{parts: list<string>, apps: list<string>} $plan
     */
    private function printHeader(
        SymfonyStyle $io,
        string $targetName,
        string $transport,
        array $available,
        array $plan,
    ): void {
        $io->writeln('');
        $io->block(
            'pinroll:deploy  →  ' . $targetName,
            'INFO',
            'fg=black;bg=cyan',
            ' ',
            true,
        );

        $io->definitionList(
            ['Target' => '<info>' . $targetName . '</info>'],
            ['Transport' => '<comment>' . $transport . '</comment> <fg=gray>(' . implode(' · ', $available) . ')</>'],
            ['Parts' => '<info>' . implode(', ', $plan['parts']) . '</info>'],
            ['Apps' => $plan['apps'] !== [] ? '<info>' . implode(', ', $plan['apps']) . '</info>' : '<fg=gray>all in apps/</>'],
            ['Apply' => '<fg=green>yes</> <fg=gray>(push + apply)</>'],
        );
        $io->newLine();
    }
}
