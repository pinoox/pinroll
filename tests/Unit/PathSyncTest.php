<?php

use Pinoox\Pinroll\Support\PincorePaths;
use Pinoox\Pinroll\Support\SyncPathValidator;

test('sync path validator rejects unsafe remote paths', function () {
    SyncPathValidator::remoteRelative('../vendor');
})->throws(Pinoox\Pinroll\Exception\PinrollException::class);

test('sync path validator accepts relative remote path', function () {
    expect(SyncPathValidator::remoteRelative('vendor/pinoox/pincore'))
        ->toBe('vendor/pinoox/pincore');
});

test('pincore paths resolves vendor tree', function () {
    $dir = sys_get_temp_dir() . '/pinroll-pincore-' . uniqid('', true);
    $pincore = $dir . '/vendor/pinoox/pincore';
    mkdir($pincore . '/Portal', 0755, true);
    file_put_contents($pincore . '/Portal/View.php', '<?php');

    $resolved = PincorePaths::resolveLocal($dir);

    expect($resolved)->toContain('vendor/pinoox/pincore')
        ->and(PincorePaths::looksLikePincore($resolved))->toBeTrue();
});

test('pincore paths resolves project pincore fork', function () {
    $dir = sys_get_temp_dir() . '/pinroll-pincore-' . uniqid('', true);
    $pincore = $dir . '/pincore';
    mkdir($pincore, 0755, true);
    file_put_contents($pincore . '/composer.json', json_encode(['name' => 'pinoox/pincore']));

    $resolved = PincorePaths::resolveLocal($dir);

    expect(str_ends_with($resolved, '/pincore'))->toBeTrue();
});

test('progress bar formats byte sizes', function () {
    expect(Pinoox\Pinroll\Support\PushProgressBar::bytes(500))->toBe('500 B')
        ->and(Pinoox\Pinroll\Support\PushProgressBar::bytes(2048))->toBe('2 KB')
        ->and(Pinoox\Pinroll\Support\PushProgressBar::bytes(2 * 1024 * 1024))->toBe('2 MB');
});
