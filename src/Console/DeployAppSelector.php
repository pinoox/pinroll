<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Exception\PinrollException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Resolves which app packages to push/deploy for a host.
 */
final class DeployAppSelector
{
    /**
     * @param array<string, mixed> $rawHost
     * @param array<string, mixed> $cli
     * @return list<string>
     */
    public static function resolve(
        SymfonyStyle $io,
        InputInterface $input,
        array $rawHost,
        array $cli,
        ?string $projectRoot = null,
    ): array {
        $fromCli = self::fromCli($cli);
        if ($fromCli !== []) {
            return $fromCli;
        }

        $fromHost = self::fromHost($rawHost);
        if ($fromHost !== []) {
            return $fromHost;
        }

        if (!$input->isInteractive()) {
            throw new PinrollException(
                'No apps configured for this host. Run pinroll:apps, set hosts.{name}.apps in pinroll.config.php, '
                . 'or pass --app=com_package / --apps=com_a,com_b.',
            );
        }

        $io->section('Apps');
        $io->writeln(
            '<comment>No apps[] defined for this host.</comment> Select package(s) to push, '
            . 'or run <info>php pinoox pinroll:apps</info> to save defaults.',
        );
        $io->newLine();

        $selected = AppPicker::selectRequired($io, $projectRoot);

        if ($selected === []) {
            throw new PinrollException('Push cancelled — select at least one app or configure hosts.*.apps.');
        }

        return $selected;
    }

    /**
     * @param array<string, mixed> $cli
     * @return list<string>
     */
    private static function fromCli(array $cli): array
    {
        $app = $cli['app'] ?? $cli['package'] ?? null;
        if (is_string($app) && $app !== '') {
            return [$app];
        }

        $appsList = $cli['apps'] ?? null;
        if (is_string($appsList) && $appsList !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $appsList))));
        }
        if (is_array($appsList) && $appsList !== []) {
            return array_values(array_filter(array_map('strval', $appsList)));
        }

        return [];
    }

    /**
     * @param array<string, mixed> $rawHost
     * @return list<string>
     */
    private static function fromHost(array $rawHost): array
    {
        $apps = $rawHost['apps'] ?? null;
        if (!is_array($apps) || $apps === []) {
            $fallback = $rawHost['package'] ?? null;
            if (is_string($fallback) && $fallback !== '') {
                return [$fallback];
            }

            return [];
        }

        return array_values(array_filter(array_map('strval', $apps)));
    }
}
