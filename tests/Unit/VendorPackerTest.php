<?php

use Pinoox\Pinroll\Console\VendorPacker;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\ProjectPaths;

test('vendor packer zips vendor and follows package symlink', function () {
    if (!class_exists(ZipArchive::class)) {
        test()->markTestSkipped('ZipArchive extension not available');
    }

    $fixture = new Pinoox\Pinroll\Tests\Support\ProjectFixture();
    $root = $fixture->root;

    $realPinroll = $root . '/packages/pinroll';
    mkdir($realPinroll . '/src', 0755, true);
    file_put_contents($realPinroll . '/src/Pinroll.php', '<?php namespace Pinoox\\Pinroll; class Pinroll {}');

    $vendor = $root . '/vendor';
    mkdir($vendor . '/composer', 0755, true);
    file_put_contents($vendor . '/autoload.php', '<?php');
    mkdir($vendor . '/pinoox', 0755, true);
    symlink($realPinroll, $vendor . '/pinoox/pinroll');

    $paths = new NativePathResolver($root);
    $result = (new VendorPacker($paths))->pack();

    expect(is_file($result['zip']))->toBeTrue()
        ->and($result['zip'])->toBe(ProjectPaths::vendorPackZip($paths))
        ->and($result['files'])->toBeGreaterThan(0);

    $zip = new ZipArchive();
    $zip->open($result['zip']);
    expect($zip->locateName('vendor/autoload.php'))->not->toBeFalse();
    expect($zip->locateName('vendor/pinoox/pinroll/src/Pinroll.php'))->not->toBeFalse();
    $zip->close();

    $fixture->cleanup();
});
