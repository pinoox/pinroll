<?php

use Pinoox\Pinroll\Bridge\PinxPackageDetect;

test('detects app pinx without PlatformArchive::isPinxPackageArchive', function () {
    if (!class_exists(ZipArchive::class)) {
        test()->markTestSkipped('ZipArchive extension not available');
    }

    $tmp = sys_get_temp_dir() . '/pinroll-detect-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);
    $archive = $tmp . '/com_pinoox_manager.pinx';

    $zip = new ZipArchive();
    expect($zip->open($archive, ZipArchive::CREATE))->toBeTrue();
    $zip->addFromString('manifest.json', json_encode([
        'format' => 'pinx',
        'type' => 'app',
        'package' => 'com_pinoox_manager',
    ], JSON_THROW_ON_ERROR));
    $zip->addFromString('payload/app.php', "<?php return ['package' => 'com_pinoox_manager'];");
    $zip->close();

    expect(PinxPackageDetect::isPinxPackage($archive))->toBeTrue()
        ->and(PinxPackageDetect::isPlatformArchive($archive))->toBeFalse();

    @unlink($archive);
    @rmdir($tmp);
});
