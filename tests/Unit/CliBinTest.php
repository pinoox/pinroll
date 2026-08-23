<?php

use Pinoox\Pinroll\Support\CliBin;

test('cli bin rewrites php pinoox hints when PINOOX_CLI_INVOKE is pinx', function () {
    $previous = getenv('PINOOX_CLI_INVOKE');
    putenv('PINOOX_CLI_INVOKE=pinx');
    $_ENV['PINOOX_CLI_INVOKE'] = 'pinx';

    expect(CliBin::isPinx())->toBeTrue()
        ->and(CliBin::cmd('pinroll:check'))->toBe('pinx pinroll:check')
        ->and(CliBin::rewrite('php pinoox pinroll:deploy'))->toBe('pinx pinroll:deploy')
        ->and(CliBin::adaptCommand('pinx:build --yes --no-ansi'))->toBe('build --yes --no-ansi')
        ->and(CliBin::adaptCommand('fe:build com_pinoox_orbit --no-ansi'))->toBe('fe:build --no-ansi')
        ->and(CliBin::isPinxPackageCommand('build --yes --no-ansi'))->toBeTrue()
        ->and(CliBin::isPinxPackageCommand('fe:build --no-ansi'))->toBeFalse()
        ->and(CliBin::isPinxPackageCommand('pinx:build --yes --no-ansi'))->toBeTrue();

    if (is_string($previous) && $previous !== '') {
        putenv('PINOOX_CLI_INVOKE=' . $previous);
        $_ENV['PINOOX_CLI_INVOKE'] = $previous;
    } else {
        putenv('PINOOX_CLI_INVOKE');
        unset($_ENV['PINOOX_CLI_INVOKE']);
    }
});

test('pinx-root build is a package command and fe:build is not', function () {
    $previous = getenv('PINOOX_CLI_INVOKE');
    putenv('PINOOX_CLI_INVOKE');
    unset($_ENV['PINOOX_CLI_INVOKE']);

    $root = sys_get_temp_dir() . '/pinroll-cli-bin-' . uniqid('', true);
    mkdir($root . '/bin', 0755, true);
    file_put_contents($root . '/app.php', "<?php return ['package' => 'com_demo'];\n");
    file_put_contents($root . '/bin/pinx', "#!/usr/bin/env php\n");

    expect(CliBin::isPinx($root))->toBeTrue()
        ->and(CliBin::isPinxPackageCommand('build --yes --no-ansi', $root))->toBeTrue()
        ->and(CliBin::isPinxPackageCommand('fe:build --no-ansi', $root))->toBeFalse();

    if (is_string($previous) && $previous !== '') {
        putenv('PINOOX_CLI_INVOKE=' . $previous);
        $_ENV['PINOOX_CLI_INVOKE'] = $previous;
    }
});
