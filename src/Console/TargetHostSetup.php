<?php

namespace Pinoox\Pinroll\Console;

use InvalidArgumentException;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\HostDir;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\ProjectPaths;
use Pinoox\Pinroll\Target\TargetGate;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class TargetHostSetup
{
    /**
     * @return array{dir: string, gate_url: string, prompted: bool}
     */
    public static function resolve(
        SymfonyStyle $io,
        InputInterface $input,
        string $projectRoot,
        string $targetName,
    ): array {
        $paths = new NativePathResolver($projectRoot);
        Pinroll::configure([], $paths);
        $target = Pinroll::targets()->resolve($targetName);
        $transport = (string) ($target['transport'] ?? 'pinion');

        if ($transport !== 'pinion') {
            return [
                'dir' => HostDir::fromTarget($target),
                'gate_url' => '',
                'prompted' => false,
            ];
        }

        $dir = HostDir::fromTarget($target);
        $gateUrl = '';

        if ($input->isInteractive() && !(bool) $input->getOption('no-interaction')) {
            $io->writeln('<comment>Host settings for PinGate (' . $targetName . ')</comment>');

            $dir = HostDir::normalize((string) $io->ask(
                'Site subdirectory (empty = domain root; e.g. shop)',
                $dir,
            ));

            $gateUrl = self::askPinGateUrl($io, $dir);

            if ($dir !== HostDir::fromTarget($target)) {
                ConfigWriter::setTargetDir(ProjectPaths::configFile($paths), $targetName, $dir);
            }

            return [
                'dir' => $dir,
                'gate_url' => $gateUrl,
                'prompted' => true,
            ];
        }

        return [
            'dir' => $dir,
            'gate_url' => $gateUrl,
            'prompted' => false,
        ];
    }

    /**
     * PinGate init for any target (FTP, pinion, etc.).
     *
     * @return array{dir: string, gate_url: string, prompted: bool}
     */
    public static function resolveForGateInit(
        SymfonyStyle $io,
        InputInterface $input,
        string $projectRoot,
        string $targetName,
    ): array {
        $paths = new NativePathResolver($projectRoot);
        Pinroll::configure([], $paths);
        $raw = Pinroll::targets()->raw($targetName);
        $dir = HostDir::fromTarget($raw);
        $exampleUrl = TargetGate::exampleUrl($dir !== '' ? $dir : null);

        if ($input->isInteractive() && !(bool) $input->getOption('no-interaction')) {
            $io->writeln('<comment>PinGate setup (' . $targetName . ')</comment>');

            $dir = HostDir::normalize((string) $io->ask(
                'Site subdirectory (empty = domain root; e.g. shop)',
                $dir,
            ));

            $defaultUrl = TargetGate::exampleUrl($dir !== '' ? $dir : null);
            $gateUrl = self::askPinGateUrl($io, $dir, $defaultUrl);

            if ($dir !== HostDir::fromTarget($raw)) {
                ConfigWriter::setTargetDir(ProjectPaths::configFile($paths), $targetName, $dir);
            }

            return [
                'dir' => $dir,
                'gate_url' => $gateUrl,
                'prompted' => true,
            ];
        }

        return [
            'dir' => $dir,
            'gate_url' => '',
            'prompted' => false,
        ];
    }

    public static function askPinGateUrl(SymfonyStyle $io, ?string $hostDir = null, string $default = ''): string
    {
        return trim((string) $io->ask(
            'PinGate URL (domain or full URL, e.g. pinoox.com)',
            $default,
            static function (mixed $value) use ($hostDir): string {
                $value = trim((string) $value);
                if ($value === '') {
                    return '';
                }

                try {
                    return GateUrl::normalizeInput($value, $hostDir);
                } catch (InvalidArgumentException $e) {
                    throw new \RuntimeException($e->getMessage());
                }
            },
        ));
    }
}
