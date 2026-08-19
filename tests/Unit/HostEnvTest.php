<?php

use Pinoox\Pinroll\Host\HostEnv;

test('host env overlays unscoped PINROLL_* keys onto production', function () {
    $keys = [
        'PINROLL_VIA' => 'pinion',
        'PINROLL_PATH' => 'apps',
        'PINROLL_KEEP' => '0',
        'PINROLL_URL' => 'https://example.com',
        'PINROLL_TOKEN' => 'abc',
    ];
    foreach ($keys as $key => $value) {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }

    $host = HostEnv::overlay('production', [
        'via' => 'ftp',
        'deploy_path' => 'public_html',
        'keep' => 3,
    ]);

    expect($host['via'])->toBe('pinion')
        ->and($host['deploy_path'])->toBe('apps')
        ->and($host['keep'])->toBe(0)
        ->and($host['gate']['url'])->toBe('https://example.com');

    foreach (array_keys($keys) as $key) {
        putenv($key);
        unset($_ENV[$key]);
    }
});

test('host env can build a synthetic production host from env alone', function () {
    putenv('PINROLL_URL=https://shop.example.com');
    $_ENV['PINROLL_URL'] = 'https://shop.example.com';
    putenv('PINROLL_TOKEN=tok');
    $_ENV['PINROLL_TOKEN'] = 'tok';

    $host = HostEnv::synthetic('production');

    expect($host)->not->toBeNull()
        ->and($host['gate']['url'])->toBe('https://shop.example.com')
        ->and($host['via'])->toBe('ftp');

    putenv('PINROLL_URL');
    putenv('PINROLL_TOKEN');
    unset($_ENV['PINROLL_URL'], $_ENV['PINROLL_TOKEN']);
});
