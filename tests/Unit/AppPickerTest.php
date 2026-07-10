<?php

use Pinoox\Pinroll\Console\AppPicker;
use Pinoox\Pinroll\Console\ProjectPackages;

test('project packages lists enabled com_ apps', function () {
    $root = sys_get_temp_dir() . '/pinroll-app-picker-' . uniqid('', true);
    mkdir($root . '/apps/com_test_a', 0755, true);
    mkdir($root . '/apps/com_test_b', 0755, true);
    file_put_contents($root . '/apps/com_test_a/app.php', "<?php\nreturn ['package' => 'com_test_a', 'enable' => true];\n");
    file_put_contents($root . '/apps/com_test_b/app.php', "<?php\nreturn ['package' => 'com_test_b', 'enable' => true];\n");

    $apps = ProjectPackages::list($root);
    expect($apps)->toContain('com_test_a', 'com_test_b');

    unlink($root . '/apps/com_test_a/app.php');
    unlink($root . '/apps/com_test_b/app.php');
    rmdir($root . '/apps/com_test_a');
    rmdir($root . '/apps/com_test_b');
    rmdir($root . '/apps');
    rmdir($root);
});

test('project packages skips disabled apps', function () {
    $root = sys_get_temp_dir() . '/pinroll-app-picker-' . uniqid('', true);
    mkdir($root . '/apps/com_enabled', 0755, true);
    mkdir($root . '/apps/com_disabled', 0755, true);
    file_put_contents($root . '/apps/com_enabled/app.php', "<?php\nreturn ['package' => 'com_enabled', 'enable' => true];\n");
    file_put_contents($root . '/apps/com_disabled/app.php', "<?php\nreturn ['package' => 'com_disabled', 'enable' => false];\n");

    $apps = ProjectPackages::list($root);

    expect($apps)->toContain('com_enabled')->not->toContain('com_disabled');

    unlink($root . '/apps/com_enabled/app.php');
    unlink($root . '/apps/com_disabled/app.php');
    rmdir($root . '/apps/com_enabled');
    rmdir($root . '/apps/com_disabled');
    rmdir($root . '/apps');
    rmdir($root);
});

test('app picker menu uses 0 for all and 1-based app numbers', function () {
    $menu = new ReflectionMethod(AppPicker::class, 'menuChoices');
    $menu->setAccessible(true);

    $resolve = new ReflectionMethod(AppPicker::class, 'resolveNumberInput');
    $resolve->setAccessible(true);

    $apps = ['com_a', 'com_b', 'com_c'];
    $choices = $menu->invoke(null, $apps);

    expect($choices)->toBe([
        0 => '__all__',
        1 => 'com_a',
        2 => 'com_b',
        3 => 'com_c',
    ]);

    expect($resolve->invoke(null, '0', $choices, $apps))->toBe($apps)
        ->and($resolve->invoke(null, '1,3', $choices, $apps))->toBe(['com_a', 'com_c'])
        ->and($resolve->invoke(null, '2', $choices, $apps))->toBe(['com_b']);
});
