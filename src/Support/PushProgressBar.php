<?php

namespace Pinoox\Pinroll\Support;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Binds Symfony progress bars to PushProgress for deploy/push/sync.
 */
final class PushProgressBar
{
    public static function bind(OutputInterface $output, SymfonyStyle $io, bool $verbose = false): void
    {
        /** @var ProgressBar|null $bar */
        $bar = null;
        $mode = '';

        $hide = static function () use (&$bar): void {
            if ($bar !== null) {
                $bar->clear();
            }
        };

        $show = static function () use (&$bar): void {
            if ($bar !== null) {
                $bar->display();
            }
        };

        $finishBar = static function () use ($io, &$bar, &$mode): void {
            if ($bar === null) {
                return;
            }

            $bar->finish();
            $io->newLine();
            $bar = null;
            $mode = '';
        };

        $ensureDeterminate = static function (string $label) use ($output, &$bar, &$mode): ProgressBar {
            if ($bar !== null && $mode === 'bar') {
                $bar->setMessage($label);

                return $bar;
            }

            if ($bar !== null) {
                $bar->clear();
            }

            $bar = new ProgressBar($output, 100);
            $bar->setBarCharacter('<fg=green>▓</>');
            $bar->setEmptyBarCharacter('<fg=gray>░</>');
            $bar->setProgressCharacter('');
            $bar->setBarWidth(28);
            $bar->setFormat(
                '    <fg=cyan>%percent:3s%%</> <fg=green>[%bar%]</> <comment>%message%</>',
            );
            $bar->setMessage($label);
            $bar->start();
            $mode = 'bar';

            return $bar;
        };

        $ensurePulse = static function (string $label) use ($output, &$bar, &$mode): ProgressBar {
            if ($bar !== null && $mode === 'pulse') {
                $bar->setMessage($label);

                return $bar;
            }

            if ($bar !== null) {
                $bar->clear();
            }

            $bar = new ProgressBar($output);
            $bar->setBarCharacter('<fg=cyan>▓</>');
            $bar->setEmptyBarCharacter('<fg=gray>░</>');
            $bar->setProgressCharacter('<fg=green>▓</>');
            $bar->setBarWidth(28);
            $bar->setFormat('    <fg=cyan>%elapsed:6s%</> <fg=green>[%bar%]</> <comment>%message%</>');
            $bar->setMessage($label);
            $bar->start();
            $mode = 'pulse';

            return $bar;
        };

        PushProgress::bind(
            static function (string $message, string $style = PushConsole::STYLE_DEFAULT) use ($io, $hide, $show): void {
                $hide();
                $formatted = PushConsole::format($message, $style);
                if ($formatted === '') {
                    $io->newLine();
                } else {
                    $io->writeln($formatted);
                }
                $show();
            },
            $verbose,
            static function (int $current, int $total, string $label) use ($ensureDeterminate, $finishBar): void {
                if ($total <= 0) {
                    return;
                }

                $percent = (int) min(100, max(0, (int) round(($current / $total) * 100)));
                $message = $label;
                if ($total >= 1024) {
                    $message = $label . '  ' . self::bytes($current) . '/' . self::bytes($total);
                } elseif ($total > 1 && $total <= 9999 && !str_contains($label, '/')) {
                    $message = $label . '  ' . $current . '/' . $total;
                }

                $bar = $ensureDeterminate($message);
                $bar->setProgress($percent);

                if ($current >= $total) {
                    $finishBar();
                }
            },
            static function (string $label) use ($ensurePulse): void {
                $ensurePulse($label)->advance();
            },
            $finishBar,
        );
    }

    public static function bytes(int $bytes): string
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
