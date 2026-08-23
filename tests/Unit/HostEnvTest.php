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

test('host env overlays PINROLL_WEB_PATH and SSH_* onto production', function () {
    $keys = [
        'PINROLL_WEB_PATH' => 'shop',
        'PINROLL_SSH_HOST' => 'vps.example.com',
        'PINROLL_SSH_USER' => 'deploy',
    ];
    foreach ($keys as $key => $value) {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }

    $host = HostEnv::overlay('production', [
        'via' => 'ftp',
        'web_path' => '',
    ]);

    expect($host['web_path'])->toBe('shop')
        ->and($host['ssh']['host'])->toBe('vps.example.com')
        ->and($host['ssh']['user'])->toBe('deploy');

    foreach (array_keys($keys) as $key) {
        putenv($key);
        unset($_ENV[$key]);
    }
});

test('host env overlays PINROLL_DB_* and PINROLL_ADMIN_* onto production provision', function () {
    $keys = [
        'PINROLL_DB_HOST' => '127.0.0.1',
        'PINROLL_DB_DATABASE' => 'shop',
        'PINROLL_ADMIN_EMAIL' => 'ada@example.com',
        'PINROLL_LANG' => 'fa',
    ];
    foreach ($keys as $key => $value) {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }

    $host = HostEnv::overlay('production', [
        'provision' => [
            'db' => ['host' => 'localhost', 'database' => 'pinoox'],
            'user' => ['email' => 'old@example.com'],
        ],
    ]);

    expect($host['provision']['db']['host'])->toBe('127.0.0.1')
        ->and($host['provision']['db']['database'])->toBe('shop')
        ->and($host['provision']['user']['email'])->toBe('ada@example.com')
        ->and($host['lang'])->toBe('fa');

    foreach (array_keys($keys) as $key) {
        putenv($key);
        unset($_ENV[$key]);
    }
});

test('host env strips quotes from PINROLL_TOKEN so quoted .env values still overlay', function () {
    putenv('PINROLL_TOKEN="quoted-secret-token"');
    $_ENV['PINROLL_TOKEN'] = '"quoted-secret-token"';

    $host = HostEnv::overlay('production', [
        'gate' => ['token' => 'from-overlay'],
    ]);

    expect($host['gate']['token'])->toBe('quoted-secret-token');

    putenv('PINROLL_TOKEN');
    unset($_ENV['PINROLL_TOKEN']);
});
