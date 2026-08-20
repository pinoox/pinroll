<?php

use Pinoox\Pinroll\Host\RetentionPolicy;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\Config;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\StorageCleaner;

test('retention policy reads host and global settings', function () {
    $dir = sys_get_temp_dir() . '/pinroll-retention-' . uniqid('', true);
    mkdir($dir, 0755, true);

    Pinroll::configure([
        'keep' => 3,
        'store' => 'remote',
        'auto_clean' => true,
        'clean_before_deploy' => true,
        'stale_days' => 7,
        'storage_path' => $dir,
    ], new NativePathResolver($dir));

    $settings = RetentionPolicy::settings(['keep' => 2, 'store' => 'local']);

    expect($settings['keep'])->toBe(2)
        ->and($settings['store'])->toBe('local')
        ->and($settings['auto_clean'])->toBeTrue()
        ->and($settings['clean_before_deploy'])->toBeTrue()
        ->and($settings['stale_days'])->toBe(7);
});

test('retention policy skips cleanup when auto_clean is false', function () {
    $dir = sys_get_temp_dir() . '/pinroll-retention-' . uniqid('', true);
    mkdir($dir, 0755, true);

    $config = new Config(new NativePathResolver($dir), [
        'storage_path' => $dir,
        'keep' => 3,
        'auto_clean' => false,
    ]);

    $result = RetentionPolicy::cleanAfterInstall(['auto_clean' => false], [], $config);

    expect($result)->toBeNull();
});

test('retention policy cleans local storage when store is local', function () {
    $dir = sys_get_temp_dir() . '/pinroll-retention-' . uniqid('', true);
    $incoming = $dir . '/pinroll/incoming';
    mkdir($incoming, 0755, true);

    foreach (['a', 'b', 'c', 'd'] as $id) {
        file_put_contents($incoming . '/' . $id . '.pinx', 'x');
        touch($incoming . '/' . $id . '.pinx', time() + (int) $id);
    }

    Pinroll::configure([
        'storage_path' => $dir,
        'incoming_path' => 'pinroll/incoming',
        'keep' => 2,
        'store' => 'local',
        'auto_clean' => true,
    ], new NativePathResolver($dir));

    RetentionPolicy::cleanAfterInstall(['store' => 'local', 'keep' => 2, 'auto_clean' => true]);

    $remaining = glob($incoming . '/*.pinx') ?: [];

    expect(count($remaining))->toBe(2);
});

test('retention policy cleanBeforeDeploy skips when disabled', function () {
    $dir = sys_get_temp_dir() . '/pinroll-retention-' . uniqid('', true);
    mkdir($dir, 0755, true);

    Pinroll::configure([
        'storage_path' => $dir,
        'clean_before_deploy' => false,
    ], new NativePathResolver($dir));

    expect(RetentionPolicy::cleanBeforeDeploy(['clean_before_deploy' => false]))->toBeNull();
});

test('retention policy cleanBeforeDeploy prunes local leftovers', function () {
    $dir = sys_get_temp_dir() . '/pinroll-retention-' . uniqid('', true);
    $incoming = $dir . '/pinroll/incoming';
    mkdir($incoming, 0755, true);
    file_put_contents($incoming . '/chunk.part', 'partial');
    file_put_contents($incoming . '/old.pinx', 'old');
    touch($incoming . '/old.pinx', time() - (10 * 86400));

    Pinroll::configure([
        'storage_path' => $dir,
        'incoming_path' => 'pinroll/incoming',
        'keep' => 1,
        'store' => 'local',
        'clean_before_deploy' => true,
        'stale_days' => 7,
    ], new NativePathResolver($dir));

    RetentionPolicy::cleanBeforeDeploy(['store' => 'local', 'keep' => 1, 'clean_before_deploy' => true, 'stale_days' => 7]);

    expect(is_file($incoming . '/chunk.part'))->toBeFalse()
        ->and(is_file($incoming . '/old.pinx'))->toBeFalse();
});

test('storage cleaner removes stale deploy zips', function () {
    $dir = sys_get_temp_dir() . '/pinroll-clean-' . uniqid('', true);
    mkdir($dir, 0755, true);
    $zip = $dir . '/platform.zip';
    file_put_contents($zip, 'zip');
    touch($zip, time() - (3 * 86400));

    $config = new Config(new NativePathResolver($dir), [
        'storage_path' => $dir . '/storage',
    ]);

    $result = (new StorageCleaner($config))->clean([
        'keep' => 3,
        'stale_days' => 2,
        'deploy_zips' => true,
        'incoming' => false,
        'tmp' => false,
        'staging' => false,
        'sessions' => false,
        'releases' => false,
        'backups' => false,
        'pinx_export' => false,
        'pinion' => false,
        'legacy' => false,
    ]);

    expect(is_file($zip))->toBeFalse()
        ->and($result['files_deleted'])->toBe(1);
});
