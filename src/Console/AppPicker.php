<?php

namespace Pinoox\Pinroll\Console;

use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

final class AppPicker
{
    /**
     * @return list<string>|null Apps list, or null to skip (add apps[] in config later)
     */
    public static function collect(SymfonyStyle $io, ?string $projectRoot = null): ?array
    {
        $apps = ProjectPackages::list($projectRoot);

        if ($apps === []) {
            $io->warning('No apps in apps/. Add apps[] to pinroll.config.php later.');

            return null;
        }

        if (count($apps) === 1) {
            $io->text('App: <comment>' . $apps[0] . '</comment>');

            return $apps;
        }

        $io->section('Apps');

        $mode = (string) $io->choice(
            'How do you want to set apps for this target?',
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
        $choices = array_values($apps);

        foreach ($choices as $index => $app) {
            $io->writeln(sprintf('  <comment>%d</comment> %s', $index, $app));
        }

        $io->newLine();

        $question = new ChoiceQuestion(
            'Select apps (comma-separated numbers, e.g. 0,1)',
            $choices,
            '0',
        );
        $question->setMultiselect(true);

        /** @var list<int|string>|int|string $selected */
        $selected = $io->askQuestion($question);

        $resolved = self::resolveSelection($choices, $selected);

        if ($resolved === []) {
            $io->note('No apps selected. Add apps[] to pinroll.config.php later.');

            return [];
        }

        $io->text('Selected: <comment>' . implode(', ', $resolved) . '</comment>');

        return $resolved;
    }

    /**
     * @param list<string> $choices
     * @param list<int|string>|int|string $selected
     * @return list<string>
     */
    private static function resolveSelection(array $choices, array|int|string $selected): array
    {
        if (!is_array($selected)) {
            $selected = [$selected];
        }

        $resolved = [];
        foreach ($selected as $item) {
            if (is_int($item) && isset($choices[$item])) {
                $resolved[] = $choices[$item];
                continue;
            }

            if (is_string($item) && ctype_digit($item) && isset($choices[(int) $item])) {
                $resolved[] = $choices[(int) $item];
                continue;
            }

            $key = array_search((string) $item, $choices, true);
            $resolved[] = $key !== false ? $choices[$key] : (string) $item;
        }

        return array_values(array_unique(array_filter(array_map('strval', $resolved))));
    }
}
