<?php

namespace Pinoox\Terminal\Pinroll;

use Pinoox\Component\Terminal;
use Pinoox\Pinroll\Console\DeployRunner;
use Pinoox\Pinroll\Console\PinrollInput;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\NativePathResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pinroll:logs',
    description: 'View or list host or server logs for Agent inspection and diagnosis',
    aliases: ['pinroll:log'],
)]
class PinrollLogsCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->addArgument('host', InputArgument::OPTIONAL, 'Host name (omit with --local or default_host)')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host override')
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Specific log file to read (name or relative path)')
            ->addOption('latest', null, InputOption::VALUE_NONE, 'View the newest / most recent log file')
            ->addOption('lines', 'l', InputOption::VALUE_REQUIRED, 'Number of lines to tail from log', 100)
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Filter by log type (app, server, pinroll, all)', 'all')
            ->addOption('search', 's', InputOption::VALUE_REQUIRED, 'Search query for log content or file names')
            ->addOption('level', null, InputOption::VALUE_REQUIRED, 'Filter lines by log level (error, warning, info, debug)')
            ->addOption('local', null, InputOption::VALUE_NONE, 'Read logs from local machine directly')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output result as machine-readable JSON (ideal for AI Agents)')
            ->addOption('raw', null, InputOption::VALUE_NONE, 'Output raw log content without formatting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);

        try {
            $root = defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd();
            Pinroll::boot(new NativePathResolver((string) $root));

            $local = (bool) $input->getOption('local');
            $hostName = $local ? 'local' : PinrollInput::hostName($input);

            $options = [
                'host' => $hostName,
                'local' => $local,
                'file' => $input->getOption('file') ? (string) $input->getOption('file') : null,
                'latest' => (bool) $input->getOption('latest'),
                'lines' => is_numeric($input->getOption('lines')) ? (int) $input->getOption('lines') : 100,
                'type' => (string) ($input->getOption('type') ?: 'all'),
                'search' => $input->getOption('search') ? (string) $input->getOption('search') : null,
                'level' => $input->getOption('level') ? (string) $input->getOption('level') : null,
            ];

            // Filter out null options
            $options = array_filter($options, static fn ($v): bool => $v !== null);

            $runner = new DeployRunner();
            $result = $runner->logs($hostName, $options);

            // Machine-readable JSON output (for AI Agents / APIs)
            if ($input->getOption('json')) {
                $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return Command::SUCCESS;
            }

            // Raw log content output (for piping / grep)
            if ($input->getOption('raw')) {
                if (isset($result['content'])) {
                    $output->writeln($result['content']);
                } elseif (isset($result['files'])) {
                    foreach ($result['files'] as $f) {
                        $output->writeln($f['path'] . "\t" . $f['size_formatted'] . "\t" . $f['modified_formatted']);
                    }
                }

                return Command::SUCCESS;
            }

            // Human-friendly console formatting
            if (isset($result['content']) || isset($result['file'])) {
                $this->renderFileContent($io, $result, $hostName);
            } else {
                $this->renderFileList($io, $output, $result, $hostName);
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            if ($input->getOption('json')) {
                $output->writeln(json_encode([
                    'success' => false,
                    'error' => $e->getMessage(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $io->error($e->getMessage());
            }

            return Command::FAILURE;
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private function renderFileList(SymfonyStyle $io, OutputInterface $output, array $result, string $hostName): void
    {
        $files = is_array($result['files'] ?? null) ? $result['files'] : [];
        $total = (int) ($result['total'] ?? count($files));

        $io->writeln('');
        $io->block("pinroll:logs  →  {$hostName} ({$total} log files)", 'INFO', 'fg=black;bg=cyan', ' ', true);

        if ($files === []) {
            $io->warning('No log files found on host.');
            $io->writeln('<fg=gray>Checked: storage/logs/, server root (error_log), storage/pinroll/</>');

            return;
        }

        $table = new Table($output);
        $table->setHeaders(['File', 'Type', 'Size', 'Lines', 'Last Modified']);

        foreach ($files as $file) {
            $table->addRow([
                (string) ($file['name'] ?? ''),
                (string) ($file['type'] ?? ''),
                (string) ($file['size_formatted'] ?? ''),
                isset($file['lines_count']) && $file['lines_count'] !== null ? (string) $file['lines_count'] : '—',
                (string) ($file['modified_formatted'] ?? ''),
            ]);
        }

        $table->render();

        $io->writeln('');
        $firstFile = (string) ($files[0]['name'] ?? 'pinoox.log');
        $io->writeln("<fg=gray>Tip: Read a log file:</> <comment>php pinoox pinroll:logs {$hostName} --file={$firstFile} [--lines=50]</comment>");
        $io->writeln("<fg=gray>Tip: Tail latest log:</> <comment>php pinoox pinroll:logs {$hostName} --latest</comment>");
        $io->writeln("<fg=gray>Tip: Agent JSON output:</> <comment>php pinoox pinroll:logs {$hostName} --json</comment>");
    }

    /**
     * @param array<string, mixed> $result
     */
    private function renderFileContent(SymfonyStyle $io, array $result, string $hostName): void
    {
        $fileName = (string) ($result['file'] ?? 'log');
        $linesReturned = (int) ($result['lines_returned'] ?? 0);
        $totalLines = (int) ($result['total_lines'] ?? 0);

        $io->writeln('');
        $io->block("pinroll:logs  →  {$hostName}  →  {$fileName}", 'INFO', 'fg=black;bg=cyan', ' ', true);

        $io->definitionList(
            ['Path' => (string) ($result['path'] ?? '')],
            ['Type' => (string) ($result['type'] ?? '')],
            ['Size' => (string) ($result['size_formatted'] ?? '')],
            ['Modified' => (string) ($result['modified_formatted'] ?? '')],
            ['Lines' => "{$linesReturned} of {$totalLines} total"],
        );

        $io->section('Log Lines (latest last)');

        $lines = is_array($result['lines'] ?? null) ? $result['lines'] : [];
        if ($lines === []) {
            $io->note('No matching log lines found.');

            return;
        }

        foreach ($lines as $line) {
            $upper = strtoupper($line);
            if (str_contains($upper, '.ERROR:') || str_contains($upper, '.CRITICAL:') || str_contains($upper, '.ALERT:') || str_contains($upper, '.EMERGENCY:')) {
                $io->writeln('<fg=red>' . $line . '</>');
            } elseif (str_contains($upper, '.WARNING:') || str_contains($upper, '.NOTICE:')) {
                $io->writeln('<fg=yellow>' . $line . '</>');
            } elseif (str_contains($upper, '.INFO:')) {
                $io->writeln('<fg=cyan>' . $line . '</>');
            } elseif (str_contains($upper, '.DEBUG:')) {
                $io->writeln('<fg=gray>' . $line . '</>');
            } else {
                $io->writeln($line);
            }
        }
    }
}
