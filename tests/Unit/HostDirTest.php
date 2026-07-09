<?php

use Pinoox\Pinroll\Console\GateUrl;
use Pinoox\Pinroll\Console\PinGateExporter;
use Pinoox\Pinroll\Support\HostDir;
use Pinoox\Pinroll\Support\ProjectPaths;

test('dir is suggested from domain', function () {
    expect(HostDir::suggestFromDomain('pinoox.com'))->toBe('pinoox');
});

test('gate url follows target dir on host', function () {
    expect(GateUrl::fromDomain('pinoox.com', 'pinoox3'))
        ->toBe('https://pinoox.com/pinoox3/pingate.php?route=');
    expect(GateUrl::fromDomain('pinoox.com'))
        ->toBe('https://pinoox.com/pingate.php?route=');
});

test('local build stays under pinroll folder', function () {
    expect(HostDir::localEntryPath())->toBe('pinroll/pingate.php');
    expect(HostDir::localGateDir())->toBe('pinroll/gate');
});

test('deploy zip mirrors pinroll layout and cleans local files', function () {
    if (!class_exists(ZipArchive::class)) {
        test()->markTestSkipped('ZipArchive extension not available');
    }

    $fixture = new Pinoox\Pinroll\Tests\Support\ProjectFixture();
    $paths = new Pinoox\Pinroll\Support\NativePathResolver($fixture->root);

    $export = (new Pinoox\Pinroll\Console\PinGateExporter($paths))->export('production', [
        'target' => 'production',
        'token_hash' => hash('sha256', 'x'),
        'dir' => 'pinoox3',
    ], true, 'pinoox3', keepLocal: false);

    expect($export['zip'])->toBe(ProjectPaths::deployZip($paths, 'production'));
    expect(is_file($export['zip']))->toBeTrue();
    expect(is_file($fixture->root . '/pinroll/pingate.php'))->toBeFalse();

    $zip = new ZipArchive();
    $zip->open((string) $export['zip']);
    expect($zip->locateName('pingate.php'))->not->toBeFalse();
    expect($zip->locateName('gate/pingate.php'))->not->toBeFalse();
    $zip->close();

    $fixture->cleanup();
});

test('dir is parsed from gate_url helper', function () {
    expect(HostDir::dirFromGateUrl('https://pinoox.com/pinoox3/pingate.php?route='))->toBe('pinoox3');
});

test('fromTarget uses config dir only', function () {
    expect(HostDir::fromTarget([
        'dir' => 'pinoox3',
        'gate_url' => 'https://pinoox.com/other/pingate.php?route=',
    ]))->toBe('pinoox3');
    expect(HostDir::fromTarget([
        'dir' => '',
        'gate_url' => 'https://pinoox.com/other/pingate.php?route=',
    ]))->toBe('');
});

test('deploy root is relative to login root without public_html', function () {
    expect(HostDir::deployRoot(''))->toBe('.');
    expect(HostDir::deployRoot('pinoox3'))->toBe('pinoox3');
    expect(HostDir::extractGuidePath(''))->toContain('public_html');
    expect(HostDir::extractGuidePath('pinoox3'))->toContain('pinoox3');
});

test('htaccess snippet includes dir prefix', function () {
    expect(PinGateExporter::htaccessSnippetContent('pinoox3'))->toContain('pinoox3/pingate');
});
