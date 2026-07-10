<?php

namespace Pinoox\Pinroll\Console;

final class SampleConfig
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function hosts(?string $stagingPackage = null): array
    {
        unset($stagingPackage);

        return [
            'production' => self::productionHost('production'),
        ];
    }

    /**
     * @deprecated Use hosts()
     * @return array<string, array<string, mixed>>
     */
    public static function targets(?string $stagingPackage = null): array
    {
        return self::hosts($stagingPackage);
    }

    /**
     * @return array<string, mixed>
     */
    public static function productionHost(string $name = 'production'): array
    {
        return [
            'deploy_path' => 'public_html',
            'via' => 'ftp',
            'gate' => self::gateBlock($name),
            'ftp' => [
                'host' => ['_env' => ConfigWriter::envKeyFor($name, 'host', 'ftp'), 'default' => ''],
                'user' => ['_env' => ConfigWriter::envKeyFor($name, 'user', 'ftp'), 'default' => ''],
                'password' => ['_env' => ConfigWriter::envKeyFor($name, 'password', 'ftp'), 'default' => ''],
            ],
        ];
    }

    /**
     * @deprecated Use productionHost()
     */
    public static function productionTarget(string $name = 'production'): array
    {
        return self::productionHost($name);
    }

    /**
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
     * @deprecated Use gateBlock()
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

    /**
     * @return array<string, mixed>
     */
    public static function globalDefaults(): array
    {
        return [
            'default_host' => 'production',
            'keep' => 3,
            'store' => 'remote',
            'auto_clean' => true,
        ];
    }
}
