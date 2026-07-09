<?php

namespace Pinoox\Terminal\Pinroll;

use Pinoox\Component\Terminal;
use Pinoox\Pinroll\Console\PinrollCli;
use Pinoox\Pinroll\Console\VendorPacker;
use Pinoox\Pinroll\Support\NativePathResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pinroll:vendor',
    description: 'Export platform vendor/ for host install or core update (resolves path-repo symlinks)',
    aliases: ['pinroll:vendor:pack', 'pinroll:pack:vendor'],
)]
class PinrollVendorPackCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output zip path (default: pinroll/vendor.zip)');
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

            $io->section('Exporting platform vendor');
            $io->writeln([
                '  Packs the full Composer <comment>vendor/</comment> tree for the host.',
                '  Use for: first install, updating <info>pinoox/pincore</info> / Packagist deps, or shipping local path-repos.',
                '  Symlinks (path repositories) are followed into real files.',
            ]);

            $result = (new VendorPacker($paths))->pack($outputZip);

            $io->newLine();
            $io->block('Vendor export ready', 'OK', 'fg=black;bg=green', ' ', true);
            $io->writeln([
                '  <fg=gray>Zip</>     <comment>' . PinrollCli::relPath($result['zip']) . '</comment>',
                '  <fg=gray>Files</>   ' . number_format($result['files']),
                '  <fg=gray>Size</>    ' . self::formatBytes($result['bytes']),
            ]);

            $io->section('On the host');
            $io->listing([
                'Upload ' . PinrollCli::relPath($result['zip']) . ' to the deploy root (e.g. public_html/)',
                'Extract so vendor/ sits next to pingate.php (replace the previous vendor/ when updating core)',
                'Path-repos (../pinroll, ../pincore3, …) are baked in as real files — preferred over a bare Packagist tree when developing core',
                'Do not upload a local .pincore that points at ../pincore3; on the host use vendor/pinoox/pincore',
                'Then: php pinoox pinroll:check production',
            ]);

            $io->writeln('  <fg=gray>Also need PinGate:</> <comment>php pinoox pinroll:gate</comment>');

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
