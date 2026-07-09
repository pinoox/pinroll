<?php

use Pinoox\Pinroll\Support\IncomingRelease;

test('resolveInstallable returns pinx path as-is', function () {
    $dir = sys_get_temp_dir() . '/pinroll-pinx-' . uniqid('', true);
    mkdir($dir, 0755, true);
    $pinx = $dir . '/pkg.pinx';
    file_put_contents($pinx, 'PK');

    expect(IncomingRelease::resolveInstallable($pinx, $dir . '/work'))->toBe($pinx);

    @unlink($pinx);
    @rmdir($dir);
});

test('resolveInstallable extracts pinx from tar wrapper', function () {
    if (!class_exists(PharData::class)) {
        $this->markTestSkipped('PharData not available');
    }

    $root = sys_get_temp_dir() . '/pinroll-tar-' . uniqid('', true);
    $work = $root . '/work';
    mkdir($root, 0755, true);

    $pinxName = 'demo_v1.pinx';
    $pinxPath = $root . '/' . $pinxName;
    file_put_contents($pinxPath, "PK\x03\x04demo");

    $tarPath = $root . '/20260709_200200_abcd.tar';
    $phar = new PharData($tarPath);
    $phar->addFile($pinxPath, $pinxName);
    unset($phar);

    $installable = IncomingRelease::resolveInstallable($tarPath, $work);

    expect($installable)->toEndWith('.pinx')
        ->and(is_file($installable))->toBeTrue();

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($root);
});
