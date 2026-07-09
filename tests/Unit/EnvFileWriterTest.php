<?php

use Pinoox\Pinroll\Console\EnvFileWriter;

test('env file writer merges and updates pinroll keys', function () {
    $path = sys_get_temp_dir() . '/pinroll-env-test-' . uniqid('', true) . '.env';
    file_put_contents($path, "APP_NAME=Pinoox\nPINROLL_PRODUCTION_URL=old\n");

    EnvFileWriter::merge($path, [
        'PINROLL_PRODUCTION_URL' => 'https://pinoox.com/pingate.php?route=',
        'PINROLL_PRODUCTION_TOKEN' => 'secret-token',
    ]);

    $contents = (string) file_get_contents($path);

    expect($contents)
        ->toContain('APP_NAME=Pinoox')
        ->toContain('PINROLL_PRODUCTION_URL=')
        ->toContain('https://pinoox.com/pingate.php?route=')
        ->toContain('PINROLL_PRODUCTION_TOKEN=secret-token')
        ->not->toContain('PINROLL_PRODUCTION_URL=old');

    expect(getenv('PINROLL_PRODUCTION_TOKEN'))->toBe('secret-token');

    @unlink($path);
});

test('env file writer appends new keys as one block with a single comment', function () {
    $path = sys_get_temp_dir() . '/pinroll-env-block-' . uniqid('', true) . '.env';
    file_put_contents($path, "APP_NAME=Pinoox\n");

    EnvFileWriter::merge($path, [
        'PINROLL_PRODUCTION_HOST' => '',
        'PINROLL_PRODUCTION_USER' => '',
        'PINROLL_PRODUCTION_PASSWORD' => '',
        'PINROLL_PRODUCTION_URL' => '',
        'PINROLL_PRODUCTION_TOKEN' => '',
    ]);

    $contents = (string) file_get_contents($path);
    $commentCount = substr_count($contents, '# Pinroll');

    expect($commentCount)->toBe(1);
    expect($contents)->toMatch(
        '/# Pinroll[^\n]*\nPINROLL_PRODUCTION_HOST=\nPINROLL_PRODUCTION_USER=\nPINROLL_PRODUCTION_PASSWORD=\nPINROLL_PRODUCTION_URL=\nPINROLL_PRODUCTION_TOKEN=\n/'
    );
    expect($contents)->not->toContain("# Pinroll\nPINROLL_PRODUCTION_HOST=\n\n# Pinroll");

    @unlink($path);
});
