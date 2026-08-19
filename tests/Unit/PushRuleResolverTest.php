<?php

use Pinoox\Pinroll\Console\PushRuleResolver;
use Pinoox\Pinroll\Console\SampleConfig;

test('push rule resolver uses default app rule', function () {
    $target = [
        'apps' => ['com_pinoox_manager'],
        'rules' => [
            'all' => ['app', 'vendor', 'theme'],
            'app' => ['app'],
        ],
    ];

    $plan = PushRuleResolver::resolve($target);

    expect($plan['rule'])->toBe('app');
    expect($plan['parts'])->toBe(['app']);
    expect($plan['apps'])->toBe(['com_pinoox_manager']);
    expect($plan['vendor'])->toBeFalse();
});

test('push rule resolver honors full flag as platform plus apps', function () {
    $target = [
        'apps' => ['com_pinoox_manager'],
        'rules' => ['all' => ['app', 'vendor', 'theme', 'platform']],
    ];

    $plan = PushRuleResolver::resolve($target, ['full' => true]);

    expect($plan['parts'])->toBe(['platform', 'app'])
        ->and($plan['platform'])->toBeTrue()
        ->and($plan['app'])->toBeTrue()
        ->and($plan['vendor'])->toBeFalse()
        ->and($plan['apps'])->toBe(['com_pinoox_manager']);
});

test('push rule resolver combines vendor and theme flags', function () {
    $target = [
        'apps' => ['com_pinoox_manager'],
        'rules' => [
            'all' => ['app', 'vendor', 'theme'],
            'app' => ['app'],
        ],
    ];

    $plan = PushRuleResolver::resolve($target, ['vendor' => true, 'theme' => true]);

    expect($plan['parts'])->toBe(['vendor', 'theme', 'app']);
    expect($plan['app'])->toBeTrue();
    expect($plan['theme'])->toBeTrue();
    expect($plan['vendor'])->toBeTrue();
});

test('push rule resolver returns empty apps when host has no apps configured', function () {
    $target = ['rules' => ['app' => ['app']]];

    $plan = PushRuleResolver::resolve($target);

    expect($plan['apps'])->toBe([]);
});

test('push rule resolver throws for unknown rule', function () {
    $target = [
        'apps' => ['com_pinoox_manager'],
        'rules' => ['app' => ['app']],
    ];

    PushRuleResolver::resolve($target, ['rule' => 'missing']);
})->throws(InvalidArgumentException::class);
