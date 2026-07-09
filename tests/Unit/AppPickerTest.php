<?php

use Pinoox\Pinroll\Console\ProjectPackages;

test('project packages lists com_ apps', function () {
    $root = sys_get_temp_dir() . '/pinroll-app-picker-' . uniqid('', true);
    mkdir($root . '/apps/com_test_a', 0755, true);
    mkdir($root . '/apps/com_test_b', 0755, true);

    $apps = ProjectPackages::list($root);
    expect($apps)->toContain('com_test_a', 'com_test_b');

    rmdir($root . '/apps/com_test_a');
    rmdir($root . '/apps/com_test_b');
    rmdir($root . '/apps');
    rmdir($root);
});
