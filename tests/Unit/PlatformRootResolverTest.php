<?php

use Pinoox\Pinroll\Support\PlatformRootResolver;

test('platform root resolver walks up to vendor directory', function () {
    $fixture = new Pinoox\Pinroll\Tests\Support\ProjectFixture();
    mkdir($fixture->root . '/vendor', 0755, true);
    file_put_contents($fixture->root . '/vendor/autoload.php', '<?php');
    $gateDir = $fixture->root . '/gate';
    mkdir($gateDir, 0755, true);

    expect(PlatformRootResolver::resolve($gateDir))->toBe($fixture->root);

    $fixture->cleanup();
});

test('platform root resolver skips gate vendor and uses parent platform', function () {
    $fixture = new Pinoox\Pinroll\Tests\Support\ProjectFixture();
    mkdir($fixture->root . '/vendor', 0755, true);
    file_put_contents($fixture->root . '/vendor/autoload.php', '<?php // platform');

    $gateDir = $fixture->root . '/gate';
    mkdir($gateDir . '/vendor', 0755, true);
    file_put_contents($gateDir . '/vendor/autoload.php', '<?php // gate only');

    $normalize = static fn (string $path): string => realpath($path) ?: $path;

    expect($normalize(PlatformRootResolver::resolve($gateDir)))
        ->toBe($normalize($fixture->root));
    expect($normalize(PlatformRootResolver::resolve($gateDir, ['platform_root' => '..'])))
        ->toBe($normalize($fixture->root));

    $fixture->cleanup();
});

test('platform root resolver accepts configured platform_root', function () {
    $fixture = new Pinoox\Pinroll\Tests\Support\ProjectFixture();
    mkdir($fixture->root . '/vendor', 0755, true);
    file_put_contents($fixture->root . '/vendor/autoload.php', '<?php');

    expect(PlatformRootResolver::resolve('/tmp', ['platform_root' => $fixture->root]))->toBe($fixture->root);

    $fixture->cleanup();
});
