<?php

namespace Pinoox\Pinroll\Console;

final class SampleConfig
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function hosts(string $name = 'production'): array
    {
        $name = trim($name) !== '' ? trim($name) : 'production';

        return [
            $name => self::productionHost($name),
        ];
    }

    /**
     * @deprecated Use hosts()
     * @return array<string, array<string, mixed>>
     */
    public static function targets(?string $stagingPackage = null, string $name = 'production'): array
    {
        unset($stagingPackage);

        return self::hosts($name);
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
    public static function globalDefaults(string $defaultHost = 'production'): array
    {
        $defaultHost = trim($defaultHost) !== '' ? trim($defaultHost) : 'production';

        return [
            'default_host' => $defaultHost,
            'keep' => 3,
            'store' => 'remote',
            'auto_clean' => true,
        ];
    }
}
