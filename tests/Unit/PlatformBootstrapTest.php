<?php

use Pinoox\Pinroll\Bridge\PlatformBootstrap;
use Pinoox\Pinroll\Exception\PinrollException;

beforeEach(function () {
    PlatformBootstrap::reset();
});

test('platform bootstrap requires launcher on host', function () {
    $root = sys_get_temp_dir() . '/pinroll-boot-' . uniqid('', true);
    mkdir($root . '/vendor', 0755, true);
    file_put_contents($root . '/vendor/autoload.php', '<?php return null;');

    expect(fn () => PlatformBootstrap::ensure($root))
        ->toThrow(PinrollException::class, 'platform/launcher');
});

test('platform bootstrap succeeds on local platform root', function () {
    $root = dirname(__DIR__, 3) . '/platform';
    if (!is_file($root . '/platform/launcher/core-path.php')) {
        $root = '/Applications/MAMP/htdocs/platform';
    }

    if (!is_file($root . '/platform/launcher/core-path.php')) {
        $this->markTestSkipped('Platform fixture not available');
    }

    PlatformBootstrap::ensure($root);

    expect(class_exists(\Pinoox\Portal\Pinx::class))->toBeTrue()
        ->and(\Pinoox\Portal\Pinx::installer())->toBeInstanceOf(
            \Pinoox\Component\Package\Pinx\PinxInstaller::class,
        );
});
