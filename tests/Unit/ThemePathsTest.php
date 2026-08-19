<?php

use Pinoox\Pinroll\Support\ThemePaths;

test('theme paths include pinx-root theme dist', function () {
    $root = sys_get_temp_dir() . '/pinroll-theme-' . uniqid('', true);
    $dist = $root . '/theme/spark/dist';
    mkdir($dist, 0755, true);
    file_put_contents($dist . '/app.js', 'ok');

    $folders = ThemePaths::distFolders($root, 'com_pinx_app');

    expect($folders)->toHaveCount(1)
        ->and($folders[0]['remote'])->toBe('theme/spark/dist')
        ->and(str_replace('\\', '/', $folders[0]['local']))->toBe(str_replace('\\', '/', $dist));

    @unlink($dist . '/app.js');
    @rmdir($dist);
    @rmdir($root . '/theme/spark');
    @rmdir($root . '/theme');
    @rmdir($root);
});

test('theme paths include apps package theme dist', function () {
    $root = sys_get_temp_dir() . '/pinroll-theme-app-' . uniqid('', true);
    $dist = $root . '/apps/com_demo/theme/spark/dist';
    mkdir($dist, 0755, true);
    file_put_contents($dist . '/app.js', 'ok');

    $folders = ThemePaths::distFolders($root, 'com_demo');

    expect($folders)->toHaveCount(1)
        ->and($folders[0]['remote'])->toBe('apps/com_demo/theme/spark/dist');

    @unlink($dist . '/app.js');
    @rmdir($dist);
    @rmdir($root . '/apps/com_demo/theme/spark');
    @rmdir($root . '/apps/com_demo/theme');
    @rmdir($root . '/apps/com_demo');
    @rmdir($root . '/apps');
    @rmdir($root);
});
