<?php

use Pinoox\Pinroll\Console\ConfigWriter;
use Pinoox\Pinroll\Console\InitService;

test('init scaffolds host and env keys from target name', function () {
    $root = sys_get_temp_dir() . '/pinroll-init-' . uniqid('', true);
    mkdir($root, 0755, true);
    file_put_contents($root . '/.env', "APP_KEY=test\n");

    $result = (new InitService($root))->run('myconnect', force: true);

    expect($result['target'])->toBe('myconnect')
        ->and($result['host'])->toBe('myconnect')
        ->and($result['env_keys'])->toContain(ConfigWriter::envKeyFor('myconnect', 'host', 'ftp'))
        ->and($result['env_keys'])->toContain(ConfigWriter::envKeyFor('myconnect', 'url', 'pinion'));

    $config = file_get_contents($result['config']);
    expect($config)->toContain("'myconnect' => [")
        ->and($config)->toContain("'default_host' => 'myconnect'")
        ->and($config)->toContain("env('PINROLL_MYCONNECT_HOST'")
        ->and($config)->toContain("env('PINROLL_MYCONNECT_URL'")
        ->and($config)->not->toContain("'production' => [");

    $env = file_get_contents($root . '/.env');
    expect($env)->toContain('PINROLL_MYCONNECT_HOST=')
        ->and($env)->toContain('PINROLL_MYCONNECT_USER=')
        ->and($env)->toContain('PINROLL_MYCONNECT_PASSWORD=')
        ->and($env)->toContain('PINROLL_MYCONNECT_URL=')
        ->and($env)->toContain('PINROLL_MYCONNECT_TOKEN=');

    @unlink($result['config']);
    @rmdir($root . '/pinroll');
    @unlink($root . '/.env');
    @rmdir($root);
});

test('init rejects invalid host names', function () {
    $root = sys_get_temp_dir() . '/pinroll-init-bad-' . uniqid('', true);
    mkdir($root, 0755, true);

    expect(fn () => (new InitService($root))->run('My Connect'))
        ->toThrow(Pinoox\Pinroll\Exception\PinrollException::class);

    @rmdir($root);
});
