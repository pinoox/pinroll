<?php

use Pinoox\Pinroll\Console\ConfigWriter;

test('config writer renders env backed fields via template', function () {
    $rendered = ConfigWriter::render([
        'production' => [
            'via' => 'ftp',
            'dir' => '',
            'gate' => [
                'url' => ['_env' => 'PINROLL_PRODUCTION_URL', 'default' => 'https://pinoox.com/pingate.php?route='],
                'token' => ['_env' => 'PINROLL_PRODUCTION_TOKEN', 'default' => ''],
            ],
            'ftp' => [
                'host' => ['_env' => 'PINROLL_PRODUCTION_HOST', 'default' => ''],
                'user' => ['_env' => 'PINROLL_PRODUCTION_USER', 'default' => ''],
                'password' => ['_env' => 'PINROLL_PRODUCTION_PASSWORD', 'default' => ''],
            ],
        ],
    ]);

    expect($rendered)->toContain('Pinroll hosts')
        ->toContain("'hosts' => [")
        ->toContain("'deploy_path'")
        ->toContain("env('PINROLL_VIA', 'ftp')")
        ->toContain("env('PINROLL_PATH', 'public_html')")
        ->toContain("env('PINROLL_WEB_PATH', '')")
        ->toContain("env('PINROLL_KEEP', '3')")
        ->toContain('// Default host when CLI omits the host argument')
        ->toContain('SSH — SFTP upload and remote install')
        ->toContain('Pinion — chunked HTTP upload through PinGate')
        ->toContain("env('PINROLL_PRODUCTION_URL', 'https://pinoox.com/pingate.php?route=')")
        ->toContain("env('PINROLL_PRODUCTION_TOKEN', '')")
        ->toContain("'gate' => [")
        ->toContain("env('PINROLL_DB_HOST', 'localhost')")
        ->toContain("env('PINROLL_ADMIN_EMAIL', 'info@pinoox.com')")
        ->toContain("'provision' => [")
        ->toContain("'build' => [");
});

test('config writer normalizes loaded target values', function () {
    $normalized = ConfigWriter::normalizeTarget('production', [
        'transport' => 'pinion',
        'gate_url' => 'https://pinoox.com/pingate.php?route=',
        'token' => 'secret',
        'bundle' => 'platform-full',
    ]);

    expect($normalized['gate_url'])->toBe([
        '_env' => 'PINROLL_PRODUCTION_URL',
        'default' => 'https://pinoox.com/pingate.php?route=',
    ]);
    expect($normalized['transport'])->toBe('pinion');
});

test('env key helper uses target slug', function () {
    expect(ConfigWriter::envKeyFor('staging-app', 'host'))->toBe('PINROLL_STAGING_APP_HOST')
        ->and(ConfigWriter::envKeyFor('production', 'via'))->toBe('PINROLL_VIA')
        ->and(ConfigWriter::envKeyFor('production', 'path'))->toBe('PINROLL_PATH')
        ->and(ConfigWriter::envKeyFor('staging', 'via'))->toBe('PINROLL_STAGING_VIA');
});
