<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Exception\PinrollException;

final class BundleInputParser
{
    public const HELP = <<<'TEXT'
Release bundle options:
  platform              full platform (pincore + system apps) — recommended for production
  pincore               pincore / platform core only
  com_package_name      one app package
  com_a, com_b          multiple apps (saved as packages array)
TEXT;

    /**
     * @return array{bundle: string, package?: string, packages?: list<string>}
     */
    public static function parse(string $input): array
    {
        $normalized = strtolower(trim($input));

        if ($normalized === '') {
            return ['bundle' => 'platform-full'];
        }

        return match ($normalized) {
            'platform', 'platform-full', 'full' => ['bundle' => 'platform-full'],
            'pincore', 'platform-core', 'core' => ['bundle' => 'platform-core'],
            'test', 'test-empty', 'empty' => ['bundle' => 'test-empty'],
            default => self::parsePackages($input),
        };
    }

    /**
     * @return array{bundle: string, package?: string, packages?: list<string>}
     */
    private static function parsePackages(string $input): array
    {
        $parts = array_values(array_filter(array_map(
            static fn (string $part): string => trim($part),
            preg_split('/[,\s]+/', $input) ?: [],
        )));

        $packages = array_values(array_filter(
            $parts,
            static fn (string $part): bool => str_starts_with($part, 'com_'),
        ));

        if ($packages === []) {
            throw new PinrollException(
                'Unknown bundle "' . $input . '". Use: platform, pincore, or a package name like com_pinoox_developer.',
            );
        }

        if (count($packages) === 1) {
            return [
                'bundle' => 'single-app',
                'package' => $packages[0],
            ];
        }

        return [
            'bundle' => 'single-app',
            'packages' => $packages,
        ];
    }
}
