<?php

use Pinoox\Pinroll\Console\SetupPlan;

test('setup plan defaults to migrate and patch', function () {
    expect(SetupPlan::steps([]))->toBe(['migrate', 'patch']);
});

test('setup plan uses only selected step flags', function () {
    expect(SetupPlan::steps(['seed' => true]))->toBe(['seed'])
        ->and(SetupPlan::steps(['migrate' => true, 'patch' => true, 'seed' => true]))
        ->toBe(['migrate', 'seed', 'patch'])
        ->and(SetupPlan::steps(['config' => true]))->toBe(['config']);
});

test('setup plan prepends platform unless skipped', function () {
    $packages = SetupPlan::packages(['migrate' => true, 'app' => 'com_acme_shop']);
    expect($packages[0])->toBe('platform')
        ->and($packages)->toContain('com_acme_shop');

    $skipped = SetupPlan::packages([
        'migrate' => true,
        'app' => 'com_acme_shop',
        'skip_platform' => true,
    ]);
    expect($skipped)->toBe(['com_acme_shop']);
});

test('setup plan config only has no packages', function () {
    expect(SetupPlan::packages(['config' => true]))->toBe([]);
});

test('setup plan uses host apps when cli omits app', function () {
    $packages = SetupPlan::packages(
        ['migrate' => true, 'skip_platform' => true],
        ['apps' => ['com_pinoox_manager', 'com_pinoox_welcome']],
    );

    expect($packages)->toBe(['com_pinoox_manager', 'com_pinoox_welcome']);
});
