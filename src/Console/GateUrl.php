<?php

namespace Pinoox\Pinroll\Console;

use InvalidArgumentException;
use Pinoox\Pinroll\Support\HostDir;

final class GateUrl
{
    public static function fromDomain(string $domain, ?string $hostDir = null): string
    {
        return HostDir::gateUrlFromDomain($domain, $hostDir);
    }

    public static function normalizeInputOrEmpty(string $input, ?string $hostDir = null): string
    {
        $input = trim($input);
        if ($input === '') {
            return '';
        }

        return self::normalizeInput($input, $hostDir);
    }

    public static function normalizeInput(string $input, ?string $hostDir = null): string
    {
        $input = trim($input);
        if ($input === '') {
            throw new InvalidArgumentException('PinGate URL is required.');
        }

        if (!preg_match('#^https?://#i', $input) && !str_contains($input, '/')) {
            return rtrim(self::fromDomain(self::normalizeDomain($input), $hostDir), '/');
        }

        if (!preg_match('#^https?://#i', $input)) {
            $input = 'https://' . ltrim($input, '/');
        }

        $host = parse_url($input, PHP_URL_HOST);
        if (!is_string($host) || $host === '' || !self::isValidDomainHost($host)) {
            throw new InvalidArgumentException(self::invalidHostMessage());
        }

        return self::ensureRouteSuffix(rtrim($input, '/'), $hostDir);
    }

    public static function normalizeDomain(string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            throw new InvalidArgumentException(self::invalidHostMessage());
        }

        if (preg_match('#^https?://#i', $input)) {
            $host = parse_url($input, PHP_URL_HOST);
            if (!is_string($host) || $host === '') {
                throw new InvalidArgumentException(self::invalidHostMessage());
            }
            $input = $host;
        }

        if (str_contains($input, '/')) {
            $input = (string) strtok($input, '/');
        }

        $input = strtolower(rtrim($input, '.'));

        if (!self::isValidDomainHost($input)) {
            throw new InvalidArgumentException(self::invalidHostMessage());
        }

        return $input;
    }

    public static function isValidDomainHost(string $host): bool
    {
        $host = strtolower(trim($host, '.'));

        return (bool) preg_match(
            '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i',
            $host,
        );
    }

    private static function ensureRouteSuffix(string $url, ?string $hostDir = null): string
    {
        if (str_contains($url, '?route=')) {
            return $url;
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');

        if (str_ends_with(rtrim($path, '/'), '/' . HostDir::GATE_ENTRY) || str_ends_with(rtrim($path, '/'), HostDir::GATE_ENTRY)) {
            return $url . '?route=';
        }

        return rtrim($url, '/') . '/' . HostDir::gateEntryWebPath($hostDir) . '?route=';
    }

    private static function invalidHostMessage(): string
    {
        return 'Invalid URL — use a name.domain style host (e.g. pinoox.com).';
    }
}
