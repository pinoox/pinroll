<?php

use Pinoox\Pinroll\Host\HostConfig;

test('host config normalizes legacy targets key', function () {
    $loaded = HostConfig::normalizeLoaded([
        'targets' => [
            'production' => ['dir' => 'public_html'],
        ],
    ]);

    expect($loaded)->toHaveKey('hosts')
        ->and($loaded['hosts']['production']['dir'])->toBe('public_html');
});

test('host config merges global retention defaults into host', function () {
    $loaded = [
        'keep' => 5,
        'store' => 'both',
        'auto_clean' => false,
        'hosts' => [
            'production' => ['deploy_path' => 'public_html'],
            'staging' => ['deploy_path' => 'staging', 'keep' => 1],
        ],
    ];

    $production = HostConfig::mergeHostDefaults($loaded, $loaded['hosts']['production']);
    $staging = HostConfig::mergeHostDefaults($loaded, $loaded['hosts']['staging']);

    expect($production['keep'])->toBe(5)
        ->and($production['store'])->toBe('both')
        ->and($production['auto_clean'])->toBeFalse()
        ->and($production['deploy_path'])->toBe('public_html')
        ->and($staging['keep'])->toBe(1);
});

test('host config resolves default host name', function () {
    expect(HostConfig::defaultHostName(['default_host' => 'staging', 'hosts' => ['production' => []]]))
        ->toBe('staging');

    expect(HostConfig::defaultHostName(['hosts' => ['only' => []]]))
        ->toBe('only');

    expect(HostConfig::defaultHostName(['hosts' => ['a' => [], 'b' => []]]))
        ->toBeNull();
});

test('host config merges overlay host keys without wiping library via and ftp', function () {
    $canonical = [
        'default_host' => 'production',
        'keep' => 3,
        'hosts' => [
            'production' => [
                'via' => 'ftp',
                'deploy_path' => 'public_html',
                'gate' => ['site' => '', 'token' => ''],
                'ftp' => ['host' => '', 'user' => '', 'password' => ''],
            ],
        ],
    ];
    $overlay = [
        'hosts' => [
            'production' => [
                'gate' => [
                    'site' => 'https://pinoox.com',
                    'token' => 'shared-token',
                ],
            ],
            'staging' => [
                'via' => 'ssh',
            ],
        ],
    ];

    $merged = HostConfig::mergeDocuments($canonical, $overlay);

    expect($merged['hosts']['production']['via'])->toBe('ftp')
        ->and($merged['hosts']['production']['deploy_path'])->toBe('public_html')
        ->and($merged['hosts']['production']['ftp']['host'])->toBe('')
        ->and($merged['hosts']['production']['gate']['site'])->toBe('https://pinoox.com')
        ->and($merged['hosts']['production']['gate']['token'])->toBe('shared-token')
        ->and($merged['hosts']['staging']['via'])->toBe('ssh');
});

test('host config maps dir to deploy_path', function () {
    $merged = HostConfig::mergeHostDefaults([], ['dir' => 'public_html']);

    expect($merged['deploy_path'])->toBe('public_html')
        ->and($merged['dir'])->toBe('public_html');
});
