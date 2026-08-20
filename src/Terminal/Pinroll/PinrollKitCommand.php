<?php

namespace Pinoox\Terminal\Pinroll;

use Pinoox\Component\Terminal;
use Pinoox\Pinroll\Console\DeployRunner;
use Pinoox\Pinroll\Console\GateUrl;
use Pinoox\Pinroll\Console\OverlayWriter;
use Pinoox\Pinroll\Console\PinrollCli;
use Pinoox\Pinroll\Console\PinrollInput;
use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Host\HostGate;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\HostDir;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\PinrollAutoloader;
use Pinoox\Pinroll\Support\ProjectPaths;
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
    name: 'pinroll:kit',
    description: 'Build a PinGate zip kit to extract into public_html (no FTP)',
)]
class PinrollKitCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->addArgument('host', InputArgument::OPTIONAL, 'Host name')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host override')
            ->addOption('site', null, InputOption::VALUE_REQUIRED, 'Site origin (https://example.com)')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Deploy folder (default public_html)')
            ->addOption('rotate', null, InputOption::VALUE_NONE, 'Mint a new gate.token');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);

        try {
            $root = defined('PINOOX_BASE_PATH') ? (string) PINOOX_BASE_PATH : (string) getcwd();
            $hostName = PinrollInput::hostName($input);
            PinrollAutoloader::register($root);
            $paths = new NativePathResolver($root);
            Pinroll::boot($paths);

            $configFile = ProjectPaths::configFile($paths);
            try {
                $raw = Pinroll::hosts()->raw($hostName);
            } catch (PinrollException) {
                OverlayWriter::patch($configFile, $hostName, [
                    'via' => 'pinion',
                    'deploy_path' => 'public_html',
                ]);
                Pinroll::boot($paths);
                $raw = Pinroll::hosts()->raw($hostName);
            }

            $gate = HostGate::credentials($raw);
            $dirDefault = HostDir::fromHost($raw);
            if ($dirDefault === '') {
                $dirDefault = 'public_html';
            }

            $pathOpt = trim((string) ($input->getOption('path') ?? ''));
            $dir = HostDir::normalize($pathOpt !== '' ? $pathOpt : (
                $io->isInteractive()
                    ? (string) $io->ask('Deploy folder on host (usually public_html)', $dirDefault)
                    : $dirDefault
            ));
            if ($dir === '') {
                $dir = 'public_html';
            }

            $siteOpt = trim((string) ($input->getOption('site') ?? ''));
            $siteDefault = $gate['site'] !== ''
                ? $gate['site']
                : ($gate['url'] !== '' ? GateUrl::siteFrom($gate['url']) : '');
            $siteUrl = $siteOpt !== '' ? $siteOpt : trim((string) $io->ask(
                'Site URL (e.g. https://example.com)',
                $siteDefault !== '' ? $siteDefault : null,
            ));
            if ($siteUrl === '') {
                throw new PinrollException('Site URL is required.');
            }

            $siteUrl = GateUrl::siteFrom(GateUrl::normalizeInput($siteUrl));
            $gateUrl = GateUrl::expand($siteUrl);

            OverlayWriter::patch($configFile, $hostName, [
                'via' => 'pinion',
                'deploy_path' => $dir,
                'dir' => $dir,
                'gate' => ['site' => $siteUrl],
            ]);
            Pinroll::boot($paths);

            $io->section('Pinroll kit — ' . $hostName);
            $io->writeln('  <fg=gray>Site:</> <comment>' . $siteUrl . '</comment>');
            $io->writeln('  <fg=gray>Extract into:</> <comment>' . HostDir::extractGuidePath($dir) . '</comment>');

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
            );

            try {
                $gateResult = (new DeployRunner($root))->initGate(
                    $hostName,
                    true,
                    $dir,
                    $gateUrl,
                    (bool) $input->getOption('rotate'),
                    false,
                    false,
                    null,
                    true,
                );
            } finally {
                PushProgress::bind(null);
            }

            PinrollCli::printGateInitResult($io, array_merge([
                'host' => $hostName,
                'target' => $hostName,
                'kit' => true,
            ], $gateResult));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            PushProgress::bind(null);
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
