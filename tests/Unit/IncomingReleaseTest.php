<?php

use Pinoox\Pinroll\Support\IncomingRelease;

test('incoming release lists and resolves latest tar', function () {
    $dir = sys_get_temp_dir() . '/pinroll-incoming-' . uniqid('', true);
    mkdir($dir, 0755, true);

    $older = $dir . '/20260709_100000_aaaa.tar';
    $latest = $dir . '/20260709_200000_bbbb.tar';
    file_put_contents($older, 'old');
    file_put_contents($latest, 'new');
    touch($older, time() - 3600);
    touch($latest, time());

    $list = IncomingRelease::list($dir);

    expect($list)->toHaveCount(2)
        ->and($list[0]['id'])->toBe('20260709_200000_bbbb')
        ->and(IncomingRelease::resolve($dir))->toBe($latest)
        ->and(IncomingRelease::resolve($dir, 'aaaa'))->toBe($older);
});

test('incoming release extracts pinx from tar', function () {
    $dir = sys_get_temp_dir() . '/pinroll-tar-' . uniqid('', true);
    $work = $dir . '/work';
    mkdir($dir, 0755, true);

    $pinx = $dir . '/sample.pinx';
    file_put_contents($pinx, "PK\x03\x04");

    $tar = $dir . '/release.tar';
    $phar = new PharData($tar);
    $phar->addFile($pinx, 'com_demo_v1.pinx');

    $extracted = IncomingRelease::resolveInstallable($tar, $work);

    expect(is_file($extracted))->toBeTrue()
        ->and(basename($extracted))->toBe('com_demo_v1.pinx');
});
