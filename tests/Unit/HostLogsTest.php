<?php

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\PinGate\PinGateHttpHandler;
use Pinoox\Pinroll\PinGate\PinGateRouter;
use Pinoox\Pinroll\Rollout\RolloutEngine;
use Pinoox\Pinroll\Support\Config;
use Pinoox\Pinroll\Support\HostLogs;
use Pinoox\Pinroll\Support\NativePathResolver;

test('host logs router includes logs routes', function () {
    $paths = new NativePathResolver(sys_get_temp_dir());
    $router = new PinGateRouter(new Config($paths));

    expect($router->routes())->toContain('GET /logs')
        ->and($router->routes())->toContain('POST /logs');
});

test('host logs list discovers app and server logs sorted newest first', function () {
    $root = sys_get_temp_dir() . '/pinroll-logs-' . uniqid('', true);
    $logsDir = $root . '/storage/logs';
    mkdir($logsDir, 0755, true);

    $oldLog = $logsDir . '/pinoox-2026-01-01.log';
    $newLog = $logsDir . '/pinoox-2026-02-01.log';
    $serverLog = $root . '/error_log';

    file_put_contents($oldLog, "Line 1\nLine 2\n");
    touch($oldLog, time() - 3600);

    file_put_contents($newLog, "New Line 1\nNew Line 2\n");
    touch($newLog, time());

    file_put_contents($serverLog, "Server error\n");
    touch($serverLog, time() - 1800);

    $result = HostLogs::list($root);

    expect($result['total'])->toBe(3)
        ->and($result['files'][0]['name'])->toBe('pinoox-2026-02-01.log')
        ->and($result['files'][0]['type'])->toBe('app')
        ->and($result['files'][1]['name'])->toBe('error_log')
        ->and($result['files'][1]['type'])->toBe('server')
        ->and($result['files'][2]['name'])->toBe('pinoox-2026-01-01.log');

    // Filter by type
    $appOnly = HostLogs::list($root, ['type' => 'app']);
    expect($appOnly['total'])->toBe(2)
        ->and($appOnly['files'][0]['name'])->toBe('pinoox-2026-02-01.log')
        ->and($appOnly['files'][1]['name'])->toBe('pinoox-2026-01-01.log');

    $serverOnly = HostLogs::list($root, ['type' => 'server']);
    expect($serverOnly['total'])->toBe(1)
        ->and($serverOnly['files'][0]['name'])->toBe('error_log');

    // Cleanup
    @unlink($oldLog);
    @unlink($newLog);
    @unlink($serverLog);
    @rmdir($logsDir);
    @rmdir($root . '/storage');
    @rmdir($root);
});

test('host logs read tails and filters lines', function () {
    $root = sys_get_temp_dir() . '/pinroll-logs-read-' . uniqid('', true);
    $logsDir = $root . '/storage/logs';
    mkdir($logsDir, 0755, true);

    $testLog = $logsDir . '/test.log';
    $lines = [
        '[2026-01-01] app.DEBUG: Debug message',
        '[2026-01-01] app.INFO: Info message',
        '[2026-01-01] app.WARNING: Warning message',
        '[2026-01-01] app.ERROR: Critical error occurred',
        '[2026-01-01] app.INFO: Finished task',
    ];
    file_put_contents($testLog, implode("\n", $lines) . "\n");

    // Test tailing 2 lines
    $readTail = HostLogs::read($root, 'test.log', ['lines' => 2]);
    expect($readTail['lines_returned'])->toBe(2)
        ->and($readTail['lines'])->toBe([
            '[2026-01-01] app.ERROR: Critical error occurred',
            '[2026-01-01] app.INFO: Finished task',
        ]);

    // Test search filter
    $readSearch = HostLogs::read($root, 'test.log', ['search' => 'Critical']);
    expect($readSearch['lines_returned'])->toBe(1)
        ->and($readSearch['lines'][0])->toContain('Critical error occurred');

    // Test level filter
    $readLevel = HostLogs::read($root, 'test.log', ['level' => 'ERROR']);
    expect($readLevel['lines_returned'])->toBe(1)
        ->and($readLevel['lines'][0])->toContain('app.ERROR');

    // Cleanup
    @unlink($testLog);
    @rmdir($logsDir);
    @rmdir($root . '/storage');
    @rmdir($root);
});

test('host logs blocks directory traversal attacks', function () {
    $root = sys_get_temp_dir() . '/pinroll-logs-sec-' . uniqid('', true);
    $logsDir = $root . '/storage/logs';
    mkdir($logsDir, 0755, true);

    expect(fn () => HostLogs::resolveSafePath($root, '../../etc/passwd'))
        ->toThrow(PinrollException::class);

    expect(fn () => HostLogs::resolveSafePath($root, 'storage/logs/../../secret.txt'))
        ->toThrow(PinrollException::class);

    @rmdir($logsDir);
    @rmdir($root . '/storage');
    @rmdir($root);
});

test('pingate http handler handles logs endpoint', function () {
    $root = sys_get_temp_dir() . '/pinroll-gate-logs-' . uniqid('', true);
    $logsDir = $root . '/storage/logs';
    mkdir($logsDir, 0755, true);

    $token = \Pinoox\Pinroll\Support\TokenGenerator::token();
    \Pinoox\Pinroll\PinGate\GateTokenRegistry::writeTokenFile($root, 'tester', $token);

    $logFile = $logsDir . '/pinoox-test.log';
    file_put_contents($logFile, "Line A\nLine B\n");

    $paths = new NativePathResolver($root);
    $config = new Config($paths, [
        'storage_path' => $root . '/storage',
    ]);

    $handler = new PinGateHttpHandler($config, $paths, new RolloutEngine($config, $paths));
    $result = $handler->handle('GET', 'logs', [], 'Bearer ' . $token, null);

    expect($result)->toHaveKey('files')
        ->and($result['total'])->toBe(1)
        ->and($result['files'][0]['name'])->toBe('pinoox-test.log');

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($root);
});
