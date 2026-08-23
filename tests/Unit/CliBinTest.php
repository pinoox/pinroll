<?php

use Pinoox\Pinroll\Support\CliBin;

test('cli bin rewrites php pinoox hints when PINOOX_CLI_INVOKE is pinx', function () {
    $previous = getenv('PINOOX_CLI_INVOKE');
    putenv('PINOOX_CLI_INVOKE=pinx');
    $_ENV['PINOOX_CLI_INVOKE'] = 'pinx';

    expect(CliBin::isPinx())->toBeTrue()
        ->and(CliBin::cmd('pinroll:check'))->toBe('pinx pinroll:check')
        ->and(CliBin::rewrite('php pinoox pinroll:deploy'))->toBe('pinx pinroll:deploy');

    if (is_string($previous) && $previous !== '') {
        putenv('PINOOX_CLI_INVOKE=' . $previous);
        $_ENV['PINOOX_CLI_INVOKE'] = $previous;
    } else {
        putenv('PINOOX_CLI_INVOKE');
        unset($_ENV['PINOOX_CLI_INVOKE']);
    }
});
