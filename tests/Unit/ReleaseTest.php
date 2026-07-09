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
    expect(ReleaseBundle::inferFromPackage('com_pinoox_developer'))->toBe('single-app');
});

test('bundle recipe resolves with package placeholder', function () {
    $root = sys_get_temp_dir() . '/pinroll-bundle-' . uniqid('', true);
    mkdir($root . '/pinroll/bundles', 0755, true);
    file_put_contents(
        $root . '/pinroll/bundles/single-app.php',
        <<<'PHP'
<?php return [
    'name' => 'single-app',
    'scope' => 'app',
    'build' => [['type' => 'app', 'package' => '{{package}}', 'command' => 'pinx:build {{package}}']],
];
PHP
    );

    $paths = new NativePathResolver($root);
    $config = new Config($paths, []);
    $bundle = ReleaseBundle::resolve($config, $paths, 'single-app', 'com_demo_app');

    expect($bundle->name())->toBe('single-app');
    expect($bundle->buildSteps()[0]['package'] ?? null)->toBe('com_demo_app');
});
