<?php

use Pinoox\Pinroll\Target\TargetGate;
use Pinoox\Pinroll\Target\TargetTransport;

test('target gate credentials prefer top-level gate block', function () {
    $target = [
        'dir' => 'pinoox3',
        'via' => 'ftp',
        'gate' => [
            'url' => 'https://pinoox.com/pinoox3/pingate.php?route=',
            'token' => 'secret-token',
        ],
        'ftp' => [
            'host' => 'ftp.pinoox.com',
            'user' => 'u',
            'password' => 'p',
        ],
    ];

    expect(TargetGate::isConfigured($target))->toBeTrue();

    $credentials = TargetGate::credentials($target);

    expect($credentials['url'])->toBe('https://pinoox.com/pinoox3/pingate.php?route=')
        ->and($credentials['token'])->toBe('secret-token');

    $resolved = TargetTransport::resolve($target);

    expect($resolved['transport'])->toBe('ftp')
        ->and($resolved['host'])->toBe('ftp.pinoox.com')
        ->and($resolved['gate_url'])->toBe('https://pinoox.com/pinoox3/pingate.php?route=')
        ->and($resolved['token'])->toBe('secret-token');
});

test('target gate credentials resolve legacy nested ftp.gate block', function () {
    $target = [
        'dir' => 'pinoox3',
        'via' => 'ftp',
        'ftp' => [
            'host' => 'ftp.pinoox.com',
            'user' => 'u',
            'password' => 'p',
            'gate' => [
                'url' => 'https://pinoox.com/pinoox3/pingate.php?route=',
                'token' => 'secret-token',
            ],
        ],
    ];

    expect(TargetGate::isConfigured($target))->toBeTrue();
    expect(TargetGate::credentials($target)['token'])->toBe('secret-token');
});

test('target gate falls back to pinion block credentials', function () {
    $target = [
        'via' => 'ftp',
        'ftp' => ['host' => 'ftp.pinoox.com'],
        'pinion' => ['url' => 'https://pinoox.com/pingate.php?route=', 'token' => 'tok'],
    ];

    $credentials = TargetGate::credentials($target);

    expect($credentials['url'])->toBe('https://pinoox.com/pingate.php?route=')
        ->and($credentials['token'])->toBe('tok');
});

test('target gate setup guide mentions top-level gate', function () {
    $guide = TargetGate::setupGuide('production');

    expect(implode("\n", $guide))
        ->toContain('pinroll:gate production')
        ->toContain("'gate' => [")
        ->toContain('pinroll:install production')
        ->toContain('pinroll:deploy production')
        ->toContain('pinoox.com')
        ->not->toContain("'ftp' => [");
});

test('target gate example url never uses ftp host', function () {
    $target = [
        'dir' => 'public_html/pinoox3',
        'ftp' => ['host' => 'ftp.example-host.test'],
    ];

    expect(TargetGate::suggestedUrl($target))
        ->toBe('https://pinoox.com/pinoox3/pingate.php?route=')
        ->not->toContain('example-host')
        ->not->toContain('public_html');
});

test('target gate example url uses pinoox defaults', function () {
    expect(TargetGate::exampleUrl())->toBe('https://pinoox.com/pingate.php?route=');
    expect(TargetGate::exampleUrl('shop'))->toBe('https://pinoox.com/shop/pingate.php?route=');
});

test('pinion transport can resolve from top-level gate alone', function () {
    $target = [
        'dir' => '',
        'via' => 'pinion',
        'gate' => [
            'url' => 'https://pinoox.com/pingate.php?route=',
            'token' => 'tok',
        ],
    ];

    $resolved = TargetTransport::resolve($target, 'pinion');

    expect($resolved['gate_url'])->toBe('https://pinoox.com/pingate.php?route=')
        ->and($resolved['token'])->toBe('tok');
});
