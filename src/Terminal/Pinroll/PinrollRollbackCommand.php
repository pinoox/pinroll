<?php

namespace Pinoox\Terminal\Pinroll;

use Pinoox\Component\Terminal;
use Pinoox\Pinroll\Console\DeployRunner;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\PushProgress;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pinroll:rollback',
    description: 'Rollback a target via PinGate (re-apply previous .pinx with force)',
)]
class PinrollRollbackCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->addArgument('target', InputArgument::OPTIONAL, 'Target name', 'production')
            ->addOption('deploy-id', null, InputOption::VALUE_REQUIRED, 'Deploy id to restore (omit = previous committed)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);

        PushProgress::bind(
            static function (string $message) use ($io): void {
                if ($message !== '') {
                    $io->writeln($message);
                }
            },
        );

        try {
            $root = defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd();
            Pinroll::configure([], new NativePathResolver((string) $root));

            $target = (string) $input->getArgument('target');
            $deployId = $input->getOption('deploy-id');
            $deployId = is_string($deployId) && $deployId !== '' ? $deployId : null;

            $io->writeln('');
            $io->block(
                'pinroll:rollback  →  ' . $target,
                'INFO',
                'fg=black;bg=cyan',
                ' ',
                true,
            );

            $result = (new DeployRunner((string) $root))->rollback($target, $deployId);

            $io->success('Rollback completed');
            $io->definitionList(
                ['Target' => '<info>' . $target . '</info>'],
                ['Channel' => '<comment>' . (string) ($result['channel'] ?? 'local') . '</comment>'],
                ['Status' => '<info>' . (string) ($result['status'] ?? 'rolled_back') . '</info>'],
                ['Deploy' => '<comment>' . (string) ($result['deploy_id'] ?? '—') . '</comment>'],
                ['Mode' => '<fg=gray>' . (string) ($result['mode'] ?? 'strategy') . '</>'],
            );

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } finally {
            PushProgress::bind(null);
        }
    }
}
