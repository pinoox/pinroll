<?php

use Pinoox\Pinroll\Console\BootstrapKit;
use Pinoox\Pinroll\Console\PinGateExporter;
use Pinoox\Pinroll\PinGate\GateTokenRegistry;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\ProjectPaths;
use ZipArchive;

test('bootstrap kit resolves methods', function () {
    expect(BootstrapKit::resolveMethod('kit'))->toMatchArray([
        'via' => 'pinion',
        'bootstrap_ftp' => false,
        'kit' => true,
    ])->and(BootstrapKit::resolveMethod('ftp'))->toMatchArray([
        'via' => 'ftp',
        'kit' => false,
    ])->and(BootstrapKit::resolveMethod('bootstrap-ftp'))->toMatchArray([
        'via' => 'ftp',
        'bootstrap_ftp' => true,
        'kit' => false,
    ]);
});

test('bootstrap kit readme mentions extract and deploy', function () {
    $text = BootstrapKit::readme('https://example.com', 'public_html', 'yoose');

    expect($text)->toContain('public_html')
        ->and($text)->toContain('pingate.php')
        ->and($text)->toContain('storage/pinroll/tokens/yoose.php')
        ->and($text)->toContain('pinroll:deploy')
        ->and($text)->toContain('استخراج');
});

test('pin gate kit zip includes token and readme', function () {
    $root = sys_get_temp_dir() . '/pinroll-kit-' . uniqid('', true);
    mkdir($root . '/storage/pinroll', 0755, true);
    $paths = new NativePathResolver($root);

    $tokenFile = GateTokenRegistry::writeTokenFile($root, 'dev', str_repeat('a', 64));
    $readme = $root . '/storage/pinroll/KIT-README.txt';
    file_put_contents($readme, BootstrapKit::readme('https://example.com', 'public_html', 'dev'));

    $exporter = new PinGateExporter($paths);
    $export = $exporter->export(
        'production',
        ['token_hash' => '', 'dir' => 'public_html'],
        true,
        'public_html',
        keepLocal: false,
        extraFiles: [
            $tokenFile => GateTokenRegistry::hostUploadPath('dev'),
            $readme => 'README.txt',
        ],
        kit: true,
    );

    $zipPath = (string) $export['zip'];
    expect($zipPath)->toEndWith('pinroll-kit-production.zip')
        ->and(is_file($zipPath))->toBeTrue();

    $zip = new ZipArchive();
    expect($zip->open($zipPath))->toBeTrue();
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $zip->close();

    expect($names)->toContain('pingate.php')
        ->and($names)->toContain('README.txt')
        ->and($names)->toContain('storage/pinroll/tokens/dev.php');

    @unlink($zipPath);
    @unlink($tokenFile);
    @unlink($readme);
    @rmdir($root . '/storage/pinroll/tokens');
    @rmdir($root . '/storage/pinroll');
    @rmdir($root . '/storage');
    @rmdir($root);
});

test('project paths kit zip name', function () {
    $paths = new NativePathResolver(sys_get_temp_dir());
    expect(ProjectPaths::kitZip($paths, 'production'))->toEndWith('/pinroll-kit-production.zip');
});
