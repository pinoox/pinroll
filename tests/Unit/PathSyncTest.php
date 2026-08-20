<?php

use Pinoox\Pinroll\Console\PathArchiveBuilder;
use Pinoox\Pinroll\Console\SyncPathExtractor;
use Pinoox\Pinroll\Support\PincorePaths;
use Pinoox\Pinroll\Support\SyncPathValidator;
use ZipArchive;

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

test('path archive builder zips under remote prefix', function () {
    $root = sys_get_temp_dir() . '/pinroll-archive-' . uniqid('', true);
    $local = $root . '/src';
    mkdir($local . '/Sub', 0755, true);
    file_put_contents($local . '/a.php', '<?php a;');
    file_put_contents($local . '/Sub/b.php', '<?php b;');

    $built = (new PathArchiveBuilder())->build($local, 'vendor/pinoox/pincore', $root);

    expect(is_file($built['zip']))->toBeTrue()
        ->and($built['files'])->toBe(2)
        ->and($built['deploy_id'])->toStartWith('sync_');

    $zip = new ZipArchive();
    expect($zip->open($built['zip']))->toBeTrue();
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $zip->close();

    expect($names)->toContain('vendor/pinoox/pincore/a.php')
        ->and($names)->toContain('vendor/pinoox/pincore/Sub/b.php');

    @unlink($built['zip']);
});

test('sync path extractor replaces target from incoming zip', function () {
    $root = sys_get_temp_dir() . '/pinroll-extract-' . uniqid('', true);
    $incoming = $root . '/storage/pinroll/incoming';
    mkdir($incoming, 0755, true);
    mkdir($root . '/vendor/pinoox/pincore', 0755, true);
    file_put_contents($root . '/vendor/pinoox/pincore/old.php', 'old');

    $deployId = 'sync_test_' . uniqid();
    $zipPath = $incoming . '/sync-' . $deployId . '.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('vendor/pinoox/pincore/new.php', '<?php new;');
    $zip->close();

    $result = (new SyncPathExtractor())->extract($root, [
        'deploy_id' => $deployId,
        'target' => 'vendor/pinoox/pincore',
        'delete_zip' => true,
    ]);

    expect($result['target'])->toBe('vendor/pinoox/pincore')
        ->and($result['files'])->toBe(1)
        ->and(is_file($root . '/vendor/pinoox/pincore/new.php'))->toBeTrue()
        ->and(is_file($root . '/vendor/pinoox/pincore/old.php'))->toBeFalse()
        ->and(is_file($zipPath))->toBeFalse();
});
