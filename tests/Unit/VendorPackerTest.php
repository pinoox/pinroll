<?php

use Pinoox\Pinroll\Console\VendorPacker;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\ProjectPaths;

test('vendor packer uses PlatformComposer staging and keeps pinroll from require', function () {
    if (!class_exists(ZipArchive::class)) {
        test()->markTestSkipped('ZipArchive extension not available');
    }

    if (!class_exists(\Pinoox\Component\Package\Pinx\PlatformComposer::class)) {
        test()->markTestSkipped('PlatformComposer not available');
    }

    $fixture = new Pinoox\Pinroll\Tests\Support\ProjectFixture();
    $root = $fixture->root;

    $realPinroll = $root . '/packages/pinroll';
    mkdir($realPinroll . '/src', 0755, true);
    file_put_contents($realPinroll . '/src/Pinroll.php', '<?php namespace Pinoox\\Pinroll; class Pinroll {}');

    file_put_contents($root . '/composer.json', json_encode([
        'name' => 'pinoox/test-platform',
        'require' => [
            'php' => '^8.2',
            'pinoox/pinroll' => '^1.1',
        ],
        'require-dev' => [
            'pestphp/pest' => '^2.0',
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $vendor = $root . '/vendor';
    mkdir($vendor . '/composer', 0755, true);
    mkdir($vendor . '/pestphp/pest', 0755, true);
    file_put_contents($vendor . '/autoload.php', '<?php');
    file_put_contents($vendor . '/pestphp/pest/README.md', 'dev only');
    file_put_contents($vendor . '/composer/installed.php', '<?php return ["root"=>["dev"=>true],"versions"=>["pestphp/pest"=>["dev_requirement"=>true],"pinoox/pinroll"=>["dev_requirement"=>false]]];');
    file_put_contents($vendor . '/composer/installed.json', json_encode([
        'packages' => [
            ['name' => 'pestphp/pest', 'version' => '2.0.0'],
            ['name' => 'pinoox/pinroll', 'version' => '1.1.0'],
        ],
        'dev' => true,
        'dev-package-names' => ['pestphp/pest'],
    ], JSON_PRETTY_PRINT));
    mkdir($vendor . '/pinoox', 0755, true);
    symlink($realPinroll, $vendor . '/pinoox/pinroll');

    $paths = new NativePathResolver($root);
    $result = (new VendorPacker($paths))->pack();

    expect(is_file($result['zip']))->toBeTrue()
        ->and($result['zip'])->toBe(ProjectPaths::vendorPackZip($paths))
        ->and($result['files'])->toBeGreaterThan(0)
        ->and($result['prepared'])->toBeTrue();

    $zip = new ZipArchive();
    $zip->open($result['zip']);
    expect($zip->locateName('vendor/autoload.php'))->not->toBeFalse();
    expect($zip->locateName('vendor/pinoox/pinroll/src/Pinroll.php'))->not->toBeFalse();
    // require-dev pest should be excluded by PlatformComposer strip
    expect($zip->locateName('vendor/pestphp/pest/README.md'))->toBeFalse();
    $zip->close();

    $fixture->cleanup();
});

test('vendor packer rejects pinroll only in require-dev', function () {
    $fixture = new Pinoox\Pinroll\Tests\Support\ProjectFixture();
    $root = $fixture->root;

    file_put_contents($root . '/composer.json', json_encode([
        'name' => 'pinoox/test-platform',
        'require' => ['php' => '^8.2'],
        'require-dev' => ['pinoox/pinroll' => '^1.1'],
    ], JSON_PRETTY_PRINT));

    $vendor = $root . '/vendor';
    mkdir($vendor, 0755, true);
    file_put_contents($vendor . '/autoload.php', '<?php');

    $paths = new NativePathResolver($root);

    expect(fn () => (new VendorPacker($paths))->pack())
        ->toThrow(Pinoox\Pinroll\Exception\PinrollException::class);

    $fixture->cleanup();
});
