<?php

namespace Pinoox\Pinroll\Host;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Pinroll;
use Symfony\Component\Console\Input\InputInterface;

final class HostSelector
{
    public static function resolve(InputInterface $input, ?string $positional = null): string
    {
        $explicit = trim((string) ($positional ?? ''));
        if ($explicit !== '') {
            return HostResolver::aliasName($explicit);
        }

        $fromOption = trim((string) ($input->getOption('host') ?? ''));
        if ($fromOption !== '') {
            return HostResolver::aliasName($fromOption);
        }

        $legacy = self::argument($input, 'target');
        if ($legacy !== '') {
            return HostResolver::aliasName($legacy);
        }

        $argHost = self::argument($input, 'host');
        if ($argHost !== '') {
            return HostResolver::aliasName($argHost);
        }

        $default = Pinroll::hosts()->defaultHostName();
        if ($default !== null && $default !== '') {
            return $default;
        }

        throw new PinrollException(
            'No host specified. Set default_host in pinroll.config.php or pass a host name.',
        );
    }

    public static function resolveOptional(InputInterface $input, ?string $positional = null): ?string
    {
        try {
            return self::resolve($input, $positional);
        } catch (PinrollException) {
            return null;
        }
    }

    public static function argument(InputInterface $input, string $name): string
    {
        if (!$input->hasArgument($name)) {
            return '';
        }

        $value = $input->getArgument($name);

        return is_string($value) ? trim($value) : '';
    }
}
