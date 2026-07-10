<?php

use Pinoox\Pinroll\Host\LocalArchiveStore;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Release\ReleaseManifest;
use Pinoox\Pinroll\Support\NativePathResolver;

test('local archive store keeps pinx when store is both', function () {
    $root = sys_get_temp_dir() . '/pinroll-local-store-' . uniqid('', true);
    $incoming = $root . '/storage/pinroll/incoming';
    mkdir($incoming, 0755, true);

    Pinroll::configure([
        'storage_path' => $root . '/storage',
        'incoming_path' => 'pinroll/incoming',
        'store' => 'both',
        'keep' => 1,
    ], new NativePathResolver($root));

    $source = $root . '/build/release.pinx';
    mkdir(dirname($source), 0755, true);
    file_put_contents($source, 'archive-bytes');

    $manifest = ReleaseManifest::fromArray([
        'deploy_id' => '20260710_test_store',
        'archive_path' => $source,
    ]);

    $kept = LocalArchiveStore::keep($source, $manifest, ['store' => 'both']);

    expect($kept)->not->toBeNull()
        ->and(is_file((string) $kept))->toBeTrue()
        ->and(basename((string) $kept))->toBe('20260710_test_store.pinx');
});

test('local archive store skips when store is remote', function () {
    $root = sys_get_temp_dir() . '/pinroll-local-skip-' . uniqid('', true);
    mkdir($root, 0755, true);

    Pinroll::configure([
        'store' => 'remote',
    ], new NativePathResolver($root));

    $source = $root . '/a.pinx';
    file_put_contents($source, 'x');
    $manifest = ReleaseManifest::fromArray([
        'deploy_id' => 'skip_me',
        'archive_path' => $source,
    ]);

    expect(LocalArchiveStore::keep($source, $manifest, ['store' => 'remote']))->toBeNull();
});
