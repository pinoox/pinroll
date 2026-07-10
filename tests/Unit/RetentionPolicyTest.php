<?php

use Pinoox\Pinroll\Host\RetentionPolicy;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\Config;
use Pinoox\Pinroll\Support\NativePathResolver;

test('retention policy reads host and global settings', function () {
    $dir = sys_get_temp_dir() . '/pinroll-retention-' . uniqid('', true);
    mkdir($dir, 0755, true);

    Pinroll::configure([
        'keep' => 3,
        'store' => 'remote',
        'auto_clean' => true,
        'storage_path' => $dir,
    ], new NativePathResolver($dir));

    $settings = RetentionPolicy::settings(['keep' => 2, 'store' => 'local']);

    expect($settings['keep'])->toBe(2)
        ->and($settings['store'])->toBe('local')
        ->and($settings['auto_clean'])->toBeTrue();
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
