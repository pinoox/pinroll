<?php

namespace Pinoox\Pinroll\Console;

final class SampleConfig
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function targets(?string $stagingPackage = null): array
    {
        unset($stagingPackage);

        return [
            'production' => self::productionTarget('production'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function productionTarget(string $name = 'production'): array
    {
        return [
            'dir' => '',
            'via' => 'ftp',
            'ftp' => [
                'host' => ['_env' => ConfigWriter::envKeyFor($name, 'host', 'ftp'), 'default' => ''],
                'user' => ['_env' => ConfigWriter::envKeyFor($name, 'user', 'ftp'), 'default' => ''],
                'password' => ['_env' => ConfigWriter::envKeyFor($name, 'password', 'ftp'), 'default' => ''],
            ],
        ];
    }

    /**
     * Top-level gate block for a target.
     *
     * @return array<string, mixed>
     */
    public static function gateBlock(string $name, string $gateUrl = ''): array
    {
        return [
            'url' => ['_env' => ConfigWriter::envKeyFor($name, 'url', 'pinion'), 'default' => $gateUrl],
            'token' => ['_env' => ConfigWriter::envKeyFor($name, 'token', 'pinion'), 'default' => ''],
        ];
    }

    /**
     * @deprecated Use gateBlock() — pinion credentials live in top-level gate
     * @return array<string, mixed>
     */
    public static function pinionBlock(string $name, string $gateUrl = ''): array
    {
        return self::gateBlock($name, $gateUrl);
    }

    /**
     * @return array<string, mixed>
     */
    public static function sshBlock(string $name): array
    {
        return [
            'host' => ['_env' => ConfigWriter::envKeyFor($name, 'host', 'ssh'), 'default' => ''],
            'user' => ['_env' => ConfigWriter::envKeyFor($name, 'user', 'ssh'), 'default' => ''],
            'key' => ['_env' => ConfigWriter::envKeyFor($name, 'key', 'ssh'), 'default' => ''],
        ];
    }
}
