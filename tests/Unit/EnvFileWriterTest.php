<?php

use Pinoox\Pinroll\Console\EnvFileWriter;

test('env file writer merges and updates pinroll keys', function () {
    $path = sys_get_temp_dir() . '/pinroll-env-test-' . uniqid('', true) . '.env';
    file_put_contents($path, "APP_NAME=Pinoox\nPINROLL_PRODUCTION_URL=old\n");

    EnvFileWriter::merge($path, [
        'PINROLL_PRODUCTION_URL' => 'https://pinoox.com/pinoox3/pingate.php?route=',
        'PINROLL_PRODUCTION_TOKEN' => 'secret-token',
    ]);

    $contents = (string) file_get_contents($path);

    expect($contents)
        ->toContain('APP_NAME=Pinoox')
        ->toContain('PINROLL_PRODUCTION_URL=')
        ->toContain('https://pinoox.com/pinoox3/pingate.php?route=')
        ->toContain('PINROLL_PRODUCTION_TOKEN=secret-token')
        ->not->toContain('PINROLL_PRODUCTION_URL=old');

    expect(getenv('PINROLL_PRODUCTION_TOKEN'))->toBe('secret-token');

    @unlink($path);
});
