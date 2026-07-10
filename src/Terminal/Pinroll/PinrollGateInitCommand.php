<?php

namespace Pinoox\Terminal\Pinroll;

use Pinoox\Component\Terminal;
use Pinoox\Pinroll\Console\DeployRunner;
use Pinoox\Pinroll\Console\PinrollCli;
use Pinoox\Pinroll\Console\PinrollInput;
use Pinoox\Pinroll\Console\TargetHostSetup;
use Pinoox\Pinroll\Support\PushConsole;
use Pinoox\Pinroll\Support\PushProgress;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pinroll:gate',
    description: 'Build PinGate and upload via FTP/SSH (or keep local / optional zip)',
    aliases: ['pinroll:gate:init'],
)]
class PinrollGateInitCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->addArgument('host', InputArgument::OPTIONAL, 'Host name (omit when default_host is set)')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host override')
            ->addOption('zip', 'z', InputOption::VALUE_NONE, 'Also build pinroll/deploy-{host}.zip (manual upload)')
            ->addOption('no-upload', null, InputOption::VALUE_NONE, 'Skip upload; keep files in pinroll/')
            ->addOption('with-vendor', null, InputOption::VALUE_NONE, 'Bundle pinroll vendor into gate/ (slow; only if host lacks pinroll)')
            ->addOption('rotate', null, InputOption::VALUE_NONE, 'Mint a new token (default: reuse PINROLL_*_TOKEN from .env)')
            ->addOption('via', null, InputOption::VALUE_REQUIRED, 'Transport override: ftp or ssh');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);

        try {
            $root = defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd();
            $hostName = PinrollInput::hostName($input);
            $zip = (bool) $input->getOption('zip');
            $upload = !(bool) $input->getOption('no-upload');
            $rotate = (bool) $input->getOption('rotate');
            $withVendor = (bool) $input->getOption('with-vendor');

            $host = TargetHostSetup::resolveForGateInit($io, $input, (string) $root, $hostName);
            PushProgress::bind(
                static function (string $message, string $style = PushConsole::STYLE_DEFAULT) use ($io): void {
                    $formatted = PushConsole::format($message, $style);
                    if ($formatted === '') {
                        $io->newLine();
                    } else {
                        $io->writeln($formatted);
                    }
                },
                false,
                static function (int $current, int $total, string $label) use ($io): void {
                    if ($total <= 0) {
                        return;
                    }
                    if ($current === 1 || $current === $total || $current % 50 === 0) {
                        $suffix = $label !== '' ? ' ' . $label : '';
                        $io->writeln(sprintf('  <fg=gray>%d/%d%s</>', $current, $total, $suffix));
                    }
                },
            );

            try {
                $gate = (new DeployRunner((string) $root))->initGate(
                    $hostName,
                    $zip,
                    $host['dir'],
                    $host['gate_url'] !== '' ? $host['gate_url'] : null,
                    $rotate,
                    $upload,
                    $withVendor,
                );
            } finally {
                PushProgress::bind(null);
            }

            PinrollCli::printGateInitResult($io, array_merge(['host' => $hostName, 'target' => $hostName], $gate));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            PushProgress::bind(null);
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
