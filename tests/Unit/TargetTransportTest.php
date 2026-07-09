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

test('target transport supports legacy flat config', function () {
    $target = [
        'transport' => 'ftp',
        'host' => 'legacy-host',
        'user' => 'legacy-user',
    ];

    expect(TargetTransport::names($target))->toBe(['ftp']);
    expect(TargetTransport::resolve($target)['host'])->toBe('legacy-host');
});
