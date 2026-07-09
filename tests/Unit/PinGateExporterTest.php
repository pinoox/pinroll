<?php

use Pinoox\Pinroll\Console\PinGateExporter;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\ProjectPaths;
use Pinoox\Pinroll\Tests\Support\ProjectFixture;

test('pingate exporter builds deploy zip', function () {
    if (!class_exists(ZipArchive::class)) {
        test()->markTestSkipped('ZipArchive extension not available');
    }

    $fixture = new ProjectFixture();
    $paths = new NativePathResolver($fixture->root);

    $export = (new PinGateExporter($paths))->export('production', [
        'target' => 'production',
        'token_hash' => hash('sha256', 'test-token'),
    ], true, '', keepLocal: true);

    expect($export['zip'])->toBe(ProjectPaths::deployZip($paths, 'production'));
    expect(is_file((string) $export['zip']))->toBeTrue();
    expect(is_file($export['entry']))->toBeTrue();

    $fixture->cleanup();
});
