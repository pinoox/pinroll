<?php

/**
 * Real deploy against a Pinoox platform checkout.
 *
 * Run: PINROLL_PLATFORM_TEST=1 ./vendor/bin/pest tests/Integration/PlatformDeployTest.php
 */
test('platform local deploy with empty test bundle', function () {
    if (!getenv('PINROLL_PLATFORM_TEST')) {
        test()->markTestSkipped('Set PINROLL_PLATFORM_TEST=1 to run against the platform.');
    }

    $platform = getenv('PINROLL_PLATFORM_PATH') ?: dirname(__DIR__, 3) . '/platform';
    $platform = rtrim(str_replace('\\', '/', $platform), '/');

    if (!is_file($platform . '/pinoox')) {
        test()->markTestSkipped('Platform root not found at ' . $platform);
    }

    $bundleFile = $platform . '/pinroll/bundles/test-empty.php';
    $hadBundle = is_file($bundleFile);

    if (!is_dir($platform . '/pinroll/bundles')) {
        mkdir($platform . '/pinroll/bundles', 0755, true);
    }

    if (!$hadBundle) {
        file_put_contents($bundleFile, <<<'PHP'
<?php

return [
    'name' => 'test-empty',
    'scope' => 'app',
    'build' => [],
    'depends_check' => false,
];
PHP
        );
    }

    if (!is_file($platform . '/pinroll/pinroll.config.php')) {
        test()->markTestSkipped('Run `php pinoox pinroll:init` in the platform first.');
    }

    $incoming = $platform . '/storage/pinroll/incoming';
    if (!is_dir($incoming)) {
        mkdir($incoming, 0755, true);
    }

    $runner = new Pinoox\Pinroll\Console\DeployRunner($platform);
    $result = $runner->deploy('local', ['bundle' => 'test-empty']);

    expect($result['status'])->toBe('committed');
    expect(glob($incoming . '/*') ?: [])->not->toBeEmpty();

    if (!$hadBundle && is_file($bundleFile)) {
        unlink($bundleFile);
    }
})->group('platform');
