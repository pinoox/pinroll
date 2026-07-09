<?php

use Pinoox\Pinroll\Console\ProjectInitializer;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\ProjectPaths;
use Pinoox\Pinroll\Target\TargetResolver;
use Pinoox\Pinroll\Tests\Support\ProjectFixture;
use Pinoox\Pinroll\Support\Config;

test('project initializer scaffolds pinroll config and bundles', function () {
    $fixture = new ProjectFixture();
    $paths = new NativePathResolver($fixture->root);

    $written = (new ProjectInitializer($fixture->root, true))->init();

    expect($written)->not->toBeEmpty();
    expect(ProjectPaths::isInitialized($paths))->toBeTrue();
    expect(is_file(ProjectPaths::bundleFile($paths, 'single-app')))->toBeTrue();
    expect(is_file(ProjectPaths::bundleFile($paths, 'platform-full')))->toBeTrue();

    $fixture->cleanup();
});

test('project initializer skips existing files without force', function () {
    $fixture = new ProjectFixture();
    (new ProjectInitializer($fixture->root))->init();
    $again = (new ProjectInitializer($fixture->root))->init();

    expect($again)->toBeEmpty();

    $fixture->cleanup();
});

test('target resolver loads configured targets', function () {
    $fixture = new ProjectFixture();
    $paths = new NativePathResolver($fixture->root);
    $fixture->writeConfig([
        'local' => [
            'transport' => 'local',
            'path' => $fixture->incomingDir(),
            'bundle' => 'test-empty',
        ],
    ]);
    $fixture->writeEmptyBundle();

    $resolver = new TargetResolver(new Config($paths, ['storage_path' => $fixture->root . '/storage']));
    $target = $resolver->resolve('local');

    expect($target['name'])->toBe('local');
    expect($target['transport'])->toBe('local');
    expect($target['path'])->toBe($fixture->incomingDir());

    $fixture->cleanup();
});

test('config file loader provides env helper for project config', function () {
    $fixture = new ProjectFixture();
    $configFile = $fixture->root . '/pinroll/pinroll.config.php';
    mkdir(dirname($configFile), 0755, true);
    file_put_contents($configFile, <<<'PHP'
<?php

return [
    'targets' => [
        'local' => [
            'gate_url' => env('PINROLL_TEST_URL', 'http://localhost'),
            'transport' => 'local',
        ],
    ],
];
PHP
    );

    $loaded = Pinoox\Pinroll\Support\ConfigFileLoader::load($configFile);

    expect($loaded['targets']['local']['gate_url'])->toBe('http://localhost');

    $fixture->cleanup();
});

test('target resolver throws when project config is missing', function () {
    $fixture = new ProjectFixture();
    $paths = new NativePathResolver($fixture->root);
    $resolver = new TargetResolver(new Config($paths, ['storage_path' => $fixture->root . '/storage']));

    expect(fn () => $resolver->resolve('local'))
        ->toThrow(Pinoox\Pinroll\Exception\PinrollException::class);

    $fixture->cleanup();
});
