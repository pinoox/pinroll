<?php

use Pinoox\Pinroll\Support\AppBuildPaths;

test('multi-app pinx export dir is inside apps package folder', function () {
    $root = sys_get_temp_dir() . '/pinroll-app-paths-' . uniqid('', true);
    $package = 'com_demo_app';
    mkdir($root . '/apps/' . $package, 0755, true);
    file_put_contents($root . '/apps/' . $package . '/app.php', "<?php return ['package' => '{$package}', 'version-code' => 4];\n");

    expect(AppBuildPaths::isMultiApp($root, $package))->toBeTrue()
        ->and(AppBuildPaths::pinxExportDir($root, $package))->toBe($root . '/apps/' . $package . '/pinx/export');

    $output = AppBuildPaths::nextPinxOutput($root, $package);

    expect($output)->toStartWith($root . '/apps/' . $package . '/pinx/export/' . $package . '_v4_')
        ->and($output)->toEndWith('.pinx');
});

test('single-app pinx export dir uses platform pinx workspace', function () {
    $root = sys_get_temp_dir() . '/pinroll-single-paths-' . uniqid('', true);
    $package = 'com_single_app';
    mkdir($root, 0755, true);
    file_put_contents($root . '/app.php', "<?php return ['package' => '{$package}', 'version-code' => 2];\n");

    expect(AppBuildPaths::isMultiApp($root, $package))->toBeFalse()
        ->and(AppBuildPaths::pinxExportDir($root, $package))->toBe($root . '/pinx/export/' . $package);
});
