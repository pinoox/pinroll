<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Host\HostSelector;
use Symfony\Component\Console\Input\InputInterface;

final class PinrollInput
{
    public static function hostName(InputInterface $input): string
    {
        $positional = HostSelector::argument($input, 'host');
        if ($positional === '') {
            $positional = HostSelector::argument($input, 'target');
        }

        return HostSelector::resolve($input, $positional !== '' ? $positional : null);
    }

    public static function resolveOptionalHost(InputInterface $input): ?string
    {
        $positional = HostSelector::argument($input, 'host');
        if ($positional === '') {
            $positional = HostSelector::argument($input, 'target');
        }
        if ($positional !== '') {
            return HostSelector::resolve($input, $positional);
        }

        return HostSelector::resolveOptional($input);
    }

    /**
     * @return array<string, mixed>
     */
    public static function deployOptions(InputInterface $input, bool $install = false): array
    {
        $app = $input->getOption('app');
        $apps = $input->getOption('apps');
        $legacyPackage = $input->getOption('package');

        $options = array_filter([
            'via' => ($via = (string) ($input->getOption('via') ?: '')) !== '' ? $via : null,
            'all' => $input->getOption('all') ? true : null,
            'vendor' => $input->getOption('vendor') ? true : null,
            'theme' => $input->getOption('theme') ? true : null,
            'apps' => is_string($apps) && $apps !== '' ? $apps : null,
            'app' => is_string($app) && $app !== '' ? $app : null,
            'package' => is_string($legacyPackage) && $legacyPackage !== '' ? $legacyPackage : null,
            'apply' => $install || $input->getOption('install') || $input->getOption('apply') ? true : null,
            'bundle' => ($bundle = (string) ($input->getOption('bundle') ?: '')) !== '' ? $bundle : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== false && $value !== '');

        return $options;
    }
}
