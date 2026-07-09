<?php

use Pinoox\Pinroll\PinGate\PinGateHttpHandler;
use Pinoox\Pinroll\PinGate\PinGateRouter;
use Pinoox\Pinroll\Rollout\RolloutEngine;
use Pinoox\Pinroll\Support\Config;
use Pinoox\Pinroll\Support\NativePathResolver;

test('pingate router lists incoming route', function () {
    $paths = new NativePathResolver(sys_get_temp_dir());
    $router = new PinGateRouter(new Config($paths));

    expect($router->routes())->toContain('GET /incoming');
});

test('pingate incoming lists archives newest first', function () {
    $root = sys_get_temp_dir() . '/pinroll-incoming-' . uniqid('', true);
    $incoming = $root . '/storage/pinroll/incoming';
    mkdir($incoming, 0755, true);

    $older = $incoming . '/20260101_120000_aaaa.tar';
    $newer = $incoming . '/20260709_160037_bbbb.tar';
    file_put_contents($older, 'old');
    touch($older, time() - 3600);
    file_put_contents($newer, 'new');
    touch($newer, time());

    $paths = new NativePathResolver($root);
    $config = new Config($paths, [
        'storage_path' => $root . '/storage',
        'incoming_path' => 'pinroll/incoming',
    ]);

    $handler = new PinGateHttpHandler($config, $paths, new RolloutEngine($config, $paths));
    $result = $handler->handle('GET', 'incoming', [], null, null);

    expect($result['releases'])->toHaveCount(2)
        ->and($result['releases'][0]['id'])->toBe('20260709_160037_bbbb')
        ->and($result['releases'][1]['id'])->toBe('20260101_120000_aaaa');

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($root);
});
