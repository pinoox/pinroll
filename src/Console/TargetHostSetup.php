<?php

namespace Pinoox\Pinroll\Console;

use InvalidArgumentException;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\HostDir;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\ProjectPaths;
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
                'FTP folder (subdomain folder at account root, e.g. apps)',
                $dir,
            ));

            $gateUrl = self::askPinGateUrl($io, null);

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

        if ($input->isInteractive() && !(bool) $input->getOption('no-interaction')) {
            $io->writeln('<comment>PinGate setup (' . $targetName . ')</comment>');

            $dir = HostDir::normalize((string) $io->ask(
                'FTP folder (subdomain folder at account root, e.g. apps)',
                $dir,
            ));

            $gateUrl = self::askPinGateUrl($io, null, '');

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
            'Site URL (e.g. https://apps.example.com)',
            $default,
            static function (mixed $value) use ($hostDir, $default): string {
                $value = trim((string) $value);
                if ($value === '') {
                    $value = trim($default);
                }
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
