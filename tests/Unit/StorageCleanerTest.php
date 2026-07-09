<?php

use Pinoox\Pinroll\Support\Config;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\StorageCleaner;

test('storage cleaner keeps newest incoming and removes older', function () {
    $root = sys_get_temp_dir() . '/pinroll-clean-' . uniqid('', true);
    $incoming = $root . '/storage/pinroll/incoming';
    mkdir($incoming, 0755, true);

    $older = $incoming . '/20260101_120000_aaaa.pinx';
    $newer = $incoming . '/20260709_120000_bbbb.pinx';
    file_put_contents($older, str_repeat('a', 100));
    touch($older, time() - 3600);
    file_put_contents($newer, str_repeat('b', 200));
    touch($newer, time());

    $paths = new NativePathResolver($root);
    $config = new Config($paths, [
        'storage_path' => $root . '/storage',
        'incoming_path' => 'pinroll/incoming',
    ]);

    $result = (new StorageCleaner($config))->clean([
        'keep' => 1,
        'dry_run' => false,
        'tmp' => false,
        'staging' => false,
        'sessions' => false,
        'releases' => false,
        'backups' => false,
    ]);

    expect($result['files_deleted'])->toBe(1)
        ->and(is_file($newer))->toBeTrue()
        ->and(is_file($older))->toBeFalse()
        ->and($result['bytes_freed'])->toBe(100);

    @unlink($newer);
    @rmdir($incoming);
    @rmdir($root . '/storage/pinroll');
    @rmdir($root . '/storage');
    @rmdir($root);
});

test('storage cleaner dry-run does not delete', function () {
    $root = sys_get_temp_dir() . '/pinroll-clean-dry-' . uniqid('', true);
    $incoming = $root . '/storage/pinroll/incoming';
    mkdir($incoming, 0755, true);
    $file = $incoming . '/old.pinx';
    file_put_contents($file, 'x');

    $paths = new NativePathResolver($root);
    $config = new Config($paths, [
        'storage_path' => $root . '/storage',
        'incoming_path' => 'pinroll/incoming',
    ]);

    $result = (new StorageCleaner($config))->clean([
        'keep' => 0,
        'dry_run' => true,
        'tmp' => false,
        'staging' => false,
        'sessions' => false,
        'releases' => false,
        'backups' => false,
    ]);

    expect($result['dry_run'])->toBeTrue()
        ->and($result['files_deleted'])->toBe(1)
        ->and(is_file($file))->toBeTrue();

    @unlink($file);
    @rmdir($incoming);
    @rmdir($root . '/storage/pinroll');
    @rmdir($root . '/storage');
    @rmdir($root);
});
