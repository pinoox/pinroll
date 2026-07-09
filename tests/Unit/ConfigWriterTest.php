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

    expect($rendered)->toContain('Pinroll targets')
        ->toContain("env('PINROLL_PRODUCTION_URL', 'https://pinoox.com/pingate.php?route=')")
        ->toContain("env('PINROLL_PRODUCTION_TOKEN', '')")
        ->toContain("'gate' => [")
        ->toContain("'via' => 'ftp'");
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
    expect(ConfigWriter::envKeyFor('staging-app', 'host'))->toBe('PINROLL_STAGING_APP_HOST');
});
