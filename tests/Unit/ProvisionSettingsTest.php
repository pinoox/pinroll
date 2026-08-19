<?php

use Pinoox\Pinroll\Console\ProvisionSettings;

test('provision settings merge cli over host provision defaults', function () {
    $resolved = ProvisionSettings::resolve([
        'lang' => 'en',
        'provision' => [
            'db' => [
                'host' => 'localhost',
                'database' => 'pinoox',
                'username' => 'root',
                'password' => 'secret',
            ],
            'user' => [
                'fname' => 'Ada',
                'lname' => 'Lovelace',
                'email' => 'ada@example.com',
                'username' => 'ada',
                'password' => 'secret1',
            ],
        ],
    ], [
        'db' => ['host' => '127.0.0.1'],
        'lang' => 'fa',
    ]);

    expect($resolved['db']['host'])->toBe('127.0.0.1')
        ->and($resolved['db']['database'])->toBe('pinoox')
        ->and($resolved['lang'])->toBe('fa');
});

test('provision settings validate matches installer rules', function () {
    $ok = ProvisionSettings::resolve([
        'provision' => [
            'db' => [
                'host' => 'localhost',
                'database' => 'shop',
                'username' => 'root',
                'password' => '',
                'connection' => 'mysql',
            ],
            'user' => [
                'fname' => 'Ada',
                'lname' => 'Lovelace',
                'email' => 'ada@example.com',
                'username' => 'ada_admin',
                'password' => 'secret1',
            ],
            'lang' => 'en',
        ],
    ]);

    expect(ProvisionSettings::validate($ok))->toBe([]);

    $bad = ProvisionSettings::resolve([
        'provision' => [
            'db' => ['host' => '', 'database' => '', 'username' => ''],
            'user' => [
                'fname' => 'Al',
                'lname' => 'B',
                'email' => 'not-an-email',
                'username' => 'ab',
                'password' => '123',
            ],
        ],
    ]);

    $errors = ProvisionSettings::validate($bad);
    expect($errors)->not->toBeEmpty()
        ->and(implode("\n", $errors))->toContain('Database host')
        ->and(implode("\n", $errors))->toContain('first name')
        ->and(implode("\n", $errors))->toContain('email')
        ->and(implode("\n", $errors))->toContain('username')
        ->and(implode("\n", $errors))->toContain('password');
});
