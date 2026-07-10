<?php

use Pinoox\Pinroll\Release\ReleaseBundle;
use Pinoox\Pinroll\Release\ReleaseManifest;
use Pinoox\Pinroll\Support\Config;
use Pinoox\Pinroll\Support\NativePathResolver;

test('release manifest roundtrip', function () {
    $manifest = ReleaseManifest::fromArray([
        'deploy_id' => 'test_1',
        'scope' => 'app',
        'deploy' => ['health_checks' => ['/']],
    ]);

    expect($manifest->deployId())->toBe('test_1');
    expect($manifest->healthChecks())->toBe(['/']);
});

test('single app bundle is inferred from package', function () {
    expect(ReleaseBundle::inferFromPackage('com_pinoox_developer'))->toBe('app:com_pinoox_developer');
});

test('bundle recipe resolves with package placeholder from builtin', function () {
    $root = sys_get_temp_dir() . '/pinroll-bundle-' . uniqid('', true);
    mkdir($root . '/apps/com_demo_app', 0755, true);
    file_put_contents($root . '/apps/com_demo_app/app.php', "<?php\nreturn ['package' => 'com_demo_app', 'enable' => true];\n");

    $paths = new NativePathResolver($root);
    $config = new Config($paths, []);
    $bundle = ReleaseBundle::resolveAuto($config, $paths, 'com_demo_app');

    expect($bundle->name())->toBe('app:com_demo_app');
    expect($bundle->buildSteps()[0]['package'] ?? null)->toBe('com_demo_app');
});
