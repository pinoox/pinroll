<?php

use Pinoox\Pinroll\Console\ConfigMigrator;
use Pinoox\Pinroll\Host\HostConfig;

test('config migrator rewrites targets dir and package', function () {
    $migrator = new ConfigMigrator();
    $migrated = $migrator->migrate([
        'targets' => [
            'production' => [
                'dir' => 'public_html',
                'package' => 'com_acme_shop',
                'via' => 'ftp',
            ],
        ],
    ]);

    expect($migrated['hosts']['production']['deploy_path'])->toBe('public_html')
        ->and($migrated['hosts']['production']['apps'])->toBe(['com_acme_shop'])
        ->and($migrated['hosts']['production'])->not->toHaveKey('dir')
        ->and($migrated['hosts']['production'])->not->toHaveKey('package');
});

test('config migrator detects hosts that still use dir', function () {
    $migrator = new ConfigMigrator();
    $hosts = HostConfig::hostBlocks([
        'hosts' => [
            'production' => ['dir' => 'apps', 'via' => 'ftp'],
        ],
    ]);

    expect($migrator->hostsNeedMigration($hosts))->toBeTrue();
});
