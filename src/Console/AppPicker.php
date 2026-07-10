<?php

namespace Pinoox\Pinroll\Console;

use Symfony\Component\Console\Style\SymfonyStyle;

final class AppPicker
{
    private const ALL_KEY = '__all__';

    /**
     * Interactive multiselect — at least one app required (push/deploy).
     *
     * @return list<string>
     */
    public static function selectRequired(SymfonyStyle $io, ?string $projectRoot = null): array
    {
        $apps = ProjectPackages::list($projectRoot);

        if ($apps === []) {
            $io->error('No apps found in apps/. Install an app package first.');

            return [];
        }

        if (count($apps) === 1) {
            $use = $io->confirm('Push app <comment>' . $apps[0] . '</comment>?', true);
            if (!$use) {
                return [];
            }

            return $apps;
        }

        return self::selectFromList($io, $apps);
    }

    /**
     * @return list<string>|null Apps list, or null to skip (add apps[] in config later)
     */
    public static function collect(SymfonyStyle $io, ?string $projectRoot = null): ?array
    {
        $apps = ProjectPackages::list($projectRoot);

        if ($apps === []) {
            $io->warning('No apps in apps/. Run <comment>php pinoox pinroll:apps</comment> after installing an app.');

            return null;
        }

        if (count($apps) === 1) {
            $io->text('App: <comment>' . $apps[0] . '</comment>');

            return $apps;
        }

        $io->section('Apps');

        $mode = (string) $io->choice(
            'How do you want to set apps for this host?',
            [
                'select' => 'Select from list',
                'all' => 'All apps (' . count($apps) . ')',
                'skip' => 'Skip — add apps[] in config later',
            ],
            'select',
        );

        return match ($mode) {
            'all' => $apps,
            'skip' => null,
            default => self::selectFromList($io, $apps),
        };
    }

    /**
     * @param list<string> $apps
     * @return list<string>
     */
    private static function selectFromList(SymfonyStyle $io, array $apps): array
    {
        $apps = array_values($apps);
        $menu = self::menuChoices($apps);

        self::printMenu($io, $apps);

        $default = '1';
        $answer = trim((string) $io->ask(
            'Select apps (<comment>0</comment> = all, or comma-separated numbers, e.g. 1,3)',
            $default,
        ));

        $resolved = self::resolveNumberInput($answer, $menu, $apps);

        if ($resolved === []) {
            $io->warning('No apps selected.');

            return [];
        }

        $io->text('Selected: <comment>' . implode(', ', $resolved) . '</comment>');

        return $resolved;
    }

    /**
     * @param list<string> $apps
     * @return array<int, string>
     */
    private static function menuChoices(array $apps): array
    {
        $menu = [0 => self::ALL_KEY];

        foreach ($apps as $index => $app) {
            $menu[$index + 1] = $app;
        }

        return $menu;
    }

    /**
     * @param list<string> $apps
     */
    private static function printMenu(SymfonyStyle $io, array $apps): void
    {
        $io->writeln(sprintf('  <comment>0</comment>  <info>All apps</info> <fg=gray>(%d)</>', count($apps)));

        foreach ($apps as $index => $app) {
            $io->writeln(sprintf('  <comment>%d</comment>  %s', $index + 1, $app));
        }

        $io->newLine();
    }

    /**
     * @param array<int, string> $menu
     * @param list<string> $apps
     * @return list<string>
     */
    private static function resolveNumberInput(string $answer, array $menu, array $apps): array
    {
        if ($answer === '') {
            return [];
        }

        $parts = array_values(array_filter(
            array_map('trim', preg_split('/[,\s]+/', $answer) ?: []),
            static fn (string $part): bool => $part !== '',
        ));
        if ($parts === []) {
            return [];
        }

        if (in_array('0', $parts, true)) {
            return $apps;
        }

        $resolved = [];
        foreach ($parts as $part) {
            if (!ctype_digit($part)) {
                continue;
            }

            $number = (int) $part;
            if ($number === 0) {
                return $apps;
            }

            if (!isset($menu[$number])) {
                continue;
            }

            $value = $menu[$number];
            if ($value === self::ALL_KEY) {
                return $apps;
            }

            $resolved[] = $value;
        }

        return array_values(array_unique($resolved));
    }
}
