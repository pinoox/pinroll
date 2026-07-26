<?php

namespace Pinoox\Terminal\Pinroll;

use Pinoox\Component\Terminal;
use Pinoox\Pinroll\Console\PinrollCli;
use Pinoox\Pinroll\Console\PinrollInput;
use Pinoox\Pinroll\Console\VendorPacker;
use Pinoox\Pinroll\Console\VendorPusher;
use Pinoox\Pinroll\Support\NativePathResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pinroll:vendor',
    description: 'Build a production vendor.zip via PlatformComposer (keep pinroll in require). Optional --push uploads + extracts on host.',
    aliases: ['pinroll:vendor:pack', 'pinroll:pack:vendor'],
)]
class PinrollVendorPackCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->addArgument('host', InputArgument::OPTIONAL, 'Host name when using --push (omit when default_host is set)')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output zip path (default: pinroll/vendor.zip)')
            ->addOption('push', null, InputOption::VALUE_NONE, 'FTP upload vendor.zip and extract via PinGate')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host override for --push')
            ->addOption('prune', null, InputOption::VALUE_NONE, 'Also prune vendor tests/docs (PlatformComposer vendor_prune)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);

        try {
            $root = defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd();
            $paths = new NativePathResolver((string) $root);
            $outputZip = $input->getOption('output');
            $outputZip = is_string($outputZip) && $outputZip !== '' ? $outputZip : null;
            $prune = (bool) $input->getOption('prune');
            $doPush = (bool) $input->getOption('push');

            $io->section('Exporting platform vendor');
            $io->writeln([
                '  Uses Pinoox <info>PlatformComposer</info> (same pipeline as platform build).',
                '  Strips require-dev; <comment>pinoox/pinroll</comment> must be in composer.json <info>require</info>.',
                '  Path repositories are materialized into real files.',
            ]);

            $result = (new VendorPacker($paths))->pack($outputZip, $prune);

            $io->newLine();
            $io->block('Vendor export ready', 'OK', 'fg=black;bg=green', ' ', true);
            $io->writeln([
                '  <fg=gray>Zip</>     <comment>' . PinrollCli::relPath($result['zip']) . '</comment>',
                '  <fg=gray>Files</>   ' . number_format($result['files']),
                '  <fg=gray>Size</>    ' . self::formatBytes($result['bytes']),
            ]);

            if (($result['excluded_dev_packages'] ?? []) !== []) {
                $io->writeln(
                    '  <fg=gray>Excluded require-dev</> '
                    . implode(', ', array_slice($result['excluded_dev_packages'], 0, 8))
                    . (count($result['excluded_dev_packages']) > 8 ? '…' : ''),
                );
            }

            if ($doPush) {
                $hostName = PinrollInput::hostName($input);
                $io->section('Push vendor to ' . $hostName);
                $pushed = (new VendorPusher())->push($hostName, $result['zip']);
                $io->block('Vendor uploaded and extracted on host', 'OK', 'fg=black;bg=green', ' ', true);
                $io->writeln([
                    '  <fg=gray>Remote</>  <comment>' . $pushed['remote_zip'] . '</comment>',
                    '  <fg=gray>Autoload</> '
                    . (!empty($pushed['extract']['autoload']) ? 'yes' : 'check host'),
                ]);
                $io->writeln('  Next: <comment>php pinoox pinroll:check</comment>');

                return Command::SUCCESS;
            }

            $io->section('Next');
            $io->listing([
                'Push automatically: php pinoox pinroll:vendor --push',
                'Or upload ' . PinrollCli::relPath($result['zip']) . ' next to pingate.php and POST PinGate /vendor',
                'Then: php pinoox pinroll:check',
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / (1024 * 1024), 1) . ' MB';
    }
}
