<?php

use Pinoox\Pinroll\Release\BuiltinBundle;
use Pinoox\Pinroll\Release\PlatformProfile;
use Pinoox\Pinroll\Release\ReleaseBundle;
use Pinoox\Pinroll\Support\Config;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Tests\Support\ProjectFixture;

test('platform profile detects multi-app layout', function () {
    $fixture = new ProjectFixture();
    $apps = $fixture->root . '/apps';
    mkdir($apps, 0755, true);

    foreach (['com_alpha', 'com_beta'] as $package) {
        mkdir($apps . '/' . $package, 0755, true);
        file_put_contents($apps . '/' . $package . '/app.php', "<?php\nreturn ['package' => '{$package}', 'enable' => true];\n");
    }

    $profile = PlatformProfile::fromRoot($fixture->root);

    expect($profile->layout())->toBe(PlatformProfile::LAYOUT_MULTI)
        ->and($profile->packages())->toBe(['com_alpha', 'com_beta']);

    $fixture->cleanup();
});

test('platform profile detects single-app layout', function () {
    $fixture = new ProjectFixture();
    $package = 'com_only';
    mkdir($fixture->root . '/apps/' . $package, 0755, true);
    file_put_contents(
        $fixture->root . '/apps/' . $package . '/app.php',
        "<?php\nreturn ['package' => '{$package}', 'enable' => true];\n",
    );

    $profile = PlatformProfile::fromRoot($fixture->root);

    expect($profile->layout())->toBe(PlatformProfile::LAYOUT_SINGLE)
        ->and($profile->defaultPackage())->toBe('com_only');

    $fixture->cleanup();
});

test('builtin bundle builds platform-full from discovered apps', function () {
    $fixture = new ProjectFixture();
    $apps = $fixture->root . '/apps';
    mkdir($apps . '/com_a', 0755, true);
    mkdir($apps . '/com_b', 0755, true);
    file_put_contents($apps . '/com_a/app.php', "<?php\nreturn ['package' => 'com_a', 'enable' => true];\n");
    file_put_contents($apps . '/com_b/app.php', "<?php\nreturn ['package' => 'com_b', 'enable' => true];\n");

    $recipe = BuiltinBundle::platformFull($fixture->root);

    expect($recipe['name'])->toBe('platform-full')
        ->and($recipe['build'])->toHaveCount(3)
        ->and($recipe['order'])->toBe(['platform', 'com_a', 'com_b']);

    $fixture->cleanup();
});

test('release bundle resolves without pinroll/bundles files', function () {
    $fixture = new ProjectFixture();
    $package = 'com_test_app';
    mkdir($fixture->root . '/apps/' . $package, 0755, true);
    file_put_contents(
        $fixture->root . '/apps/' . $package . '/app.php',
        "<?php\nreturn ['package' => '{$package}', 'enable' => true];\n",
    );

    $paths = new NativePathResolver($fixture->root);
    $config = new Config($paths, ['storage_path' => $fixture->root . '/storage']);

    $bundle = ReleaseBundle::resolveAuto($config, $paths, $package);

    expect($bundle->name())->toBe('app:' . $package)
        ->and($bundle->scope())->toBe('app');

    $fixture->cleanup();
});

test('release bundle test-empty works as builtin', function () {
    $fixture = new ProjectFixture();
    $paths = new NativePathResolver($fixture->root);
    $config = new Config($paths, ['storage_path' => $fixture->root . '/storage']);

    $bundle = ReleaseBundle::resolve($config, $paths, 'test-empty');

    expect($bundle->buildSteps())->toBe([]);

    $fixture->cleanup();
});
