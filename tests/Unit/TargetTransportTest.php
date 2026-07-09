<?php

use Pinoox\Pinroll\Target\TargetTransport;

test('target transport lists configured methods', function () {
    $target = [
        'via' => 'ftp',
        'ftp' => ['host' => 'ftp.example.com'],
        'pinion' => ['url' => 'https://example.com/pingate.php?route='],
    ];

    expect(TargetTransport::names($target))->toBe(['ftp', 'pinion']);
});

test('target transport resolves ftp block', function () {
    $target = [
        'dir' => 'pinoox3',
        'via' => 'ftp',
        'ftp' => ['host' => 'h', 'user' => 'u', 'password' => 'p'],
    ];

    $resolved = TargetTransport::resolve($target);

    expect($resolved['transport'])->toBe('ftp');
    expect($resolved['host'])->toBe('h');
    expect($resolved['dir'])->toBe('pinoox3');
});

test('target transport resolves pinion url field', function () {
    $target = [
        'via' => 'pinion',
        'pinion' => ['url' => 'https://site.com/pingate.php?route=', 'token' => 'secret'],
    ];

    $resolved = TargetTransport::resolve($target, 'pinion');

    expect($resolved['gate_url'])->toBe('https://site.com/pingate.php?route=');
    expect($resolved['token'])->toBe('secret');
});

test('target transport treats empty env-style ftp block as configured', function () {
    $target = [
        'via' => 'ftp',
        'ftp' => [
            'host' => '',
            'user' => '',
            'password' => '',
        ],
    ];

    expect(TargetTransport::names($target))->toContain('ftp');
    expect(TargetTransport::resolve($target)['transport'])->toBe('ftp');
});

test('target transport treats _env ftp stubs as configured', function () {
    $target = [
        'via' => 'ftp',
        'ftp' => [
            'host' => ['_env' => 'PINROLL_PRODUCTION_HOST', 'default' => ''],
            'user' => ['_env' => 'PINROLL_PRODUCTION_USER', 'default' => ''],
            'password' => ['_env' => 'PINROLL_PRODUCTION_PASSWORD', 'default' => ''],
        ],
    ];

    expect(TargetTransport::names($target))->toContain('ftp');
});
