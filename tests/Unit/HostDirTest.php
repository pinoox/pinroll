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

test('web path strips FTP docroot prefixes from public URLs', function () {
    expect(HostDir::webPath('public_html'))->toBe('');
    expect(HostDir::webPath('public_html/pinoox3'))->toBe('pinoox3');
    expect(HostDir::webPath('public_html/shop/app'))->toBe('shop/app');
    expect(HostDir::webPath('pinoox3'))->toBe('pinoox3');
    expect(HostDir::webPath('www/pinoox3'))->toBe('pinoox3');

    expect(HostDir::gateEntryWebPath('public_html/pinoox3'))
        ->toBe('pinoox3/pingate.php');
    expect(HostDir::gateEntryWebPath('public_html'))
        ->toBe('pingate.php');

    expect(GateUrl::fromDomain('pinoox.com', 'public_html/pinoox3'))
        ->toBe('https://pinoox.com/pinoox3/pingate.php?route=');
    expect(GateUrl::fromDomain('pinoox.com', 'public_html'))
        ->toBe('https://pinoox.com/pingate.php?route=');
});

test('publicHtmlPath does not double-prefix public_html', function () {
    expect(HostDir::publicHtmlPath(''))->toBe('public_html');
    expect(HostDir::publicHtmlPath('pinoox3'))->toBe('public_html/pinoox3');
    expect(HostDir::publicHtmlPath('public_html'))->toBe('public_html');
    expect(HostDir::publicHtmlPath('public_html/pinoox3'))->toBe('public_html/pinoox3');
});

test('local build stays under storage/pinroll', function () {
    expect(HostDir::localEntryPath())->toBe('storage/pinroll/pingate.php');
    expect(HostDir::localGateDir())->toBe('storage/pinroll/gate-build');
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
    expect(is_file($fixture->root . '/storage/pinroll/pingate.php'))->toBeFalse();

    $zip = new ZipArchive();
    $zip->open((string) $export['zip']);
    expect($zip->locateName('pingate.php'))->not->toBeFalse();
    expect($zip->locateName('gate/pingate.php'))->toBeFalse();
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

test('web path from host prefers explicit web_path for subdomain docroots', function () {
    expect(HostDir::webPathFromHost([
        'deploy_path' => 'app',
        'web_path' => '',
    ]))->toBe('');

    expect(HostDir::webPathFromHost([
        'deploy_path' => 'app',
    ]))->toBe('app');

    expect(HostDir::webPathFromHost([
        'deploy_path' => 'public_html/shop',
    ]))->toBe('shop');
});

test('deploy root is relative to login root without public_html', function () {
    expect(HostDir::deployRoot(''))->toBe('.');
    expect(HostDir::deployRoot('pinoox3'))->toBe('pinoox3');
    expect(HostDir::extractGuidePath(''))->toContain('public_html');
    expect(HostDir::extractGuidePath('pinoox3'))->toContain('pinoox3');
});

test('htaccess snippet includes dir prefix', function () {
    expect(PinGateExporter::htaccessSnippetContent('pinoox3'))->toContain('pinoox3/pingate');
    expect(PinGateExporter::htaccessSnippetContent('public_html/pinoox3'))->toContain('pinoox3/pingate');
    expect(PinGateExporter::htaccessSnippetContent('public_html/pinoox3'))->not->toContain('public_html');
    expect(PinGateExporter::htaccessSnippetContent('public_html'))->toContain('^pingate');
    expect(PinGateExporter::htaccessSnippetContent(''))->not->toContain('gate/');
});

test('exported pingate.php is a single file with config guard and query routing', function () {
    $fixture = new Pinoox\Pinroll\Tests\Support\ProjectFixture();
    $paths = new Pinoox\Pinroll\Support\NativePathResolver($fixture->root);

    $export = (new PinGateExporter($paths))->export('production', [
        'token_hash' => hash('sha256', 'secret-token'),
        'dir' => '',
    ], false, '', keepLocal: true);

    $contents = (string) file_get_contents($export['entry']);
    expect($contents)->toContain('PINROLL_GATE_AS_CONFIG')
        ->and($contents)->toContain("if (!function_exists('pinroll_pingate_run'))")
        ->and($contents)->toContain('pinroll_pingate_run(__DIR__, $PINROLL_GATE)')
        ->and($contents)->toContain("\$_GET['route']")
        ->and($contents)->not->toContain("dirname(\$configDir)")
        ->and($contents)->toContain("\$_FILES['chunk']");

    $fixture->cleanup();
});
