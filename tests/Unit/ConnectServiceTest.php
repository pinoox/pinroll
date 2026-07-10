<?php

use Pinoox\Pinroll\Console\ConnectService;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\NativePathResolver;

test('connect setup is complete when deploy path gate url and ftp creds exist', function () {
    $root = sys_get_temp_dir() . '/pinroll-connect-' . uniqid('', true);
    mkdir($root . '/pinroll', 0755, true);
    file_put_contents($root . '/pinroll/pinroll.config.php', <<<'PHP'
<?php

return [
    'default_host' => 'production',
    'hosts' => [
        'production' => [
            'deploy_path' => 'public_html/pinoox3',
            'via' => 'ftp',
            'gate' => [
                'url' => 'https://example.com/pinoox3/pingate.php?route=',
                'token' => 'secret',
            ],
            'ftp' => [
                'host' => 'ftp.example.com',
                'user' => 'deploy',
                'password' => 'pass',
            ],
        ],
    ],
];
PHP);

    Pinroll::boot(new NativePathResolver($root));

    expect(ConnectService::isSetupComplete('production', 'ftp'))->toBeTrue();

    @unlink($root . '/pinroll/pinroll.config.php');
    @rmdir($root . '/pinroll');
    @rmdir($root);
});

test('connect setup is incomplete when deploy path is missing', function () {
    $root = sys_get_temp_dir() . '/pinroll-connect-missing-' . uniqid('', true);
    mkdir($root . '/pinroll', 0755, true);
    file_put_contents($root . '/pinroll/pinroll.config.php', <<<'PHP'
<?php

return [
    'hosts' => [
        'production' => [
            'via' => 'ftp',
            'gate' => [
                'url' => 'https://example.com/pingate.php?route=',
                'token' => 'secret',
            ],
            'ftp' => [
                'host' => 'ftp.example.com',
                'user' => 'deploy',
                'password' => 'pass',
            ],
        ],
    ],
];
PHP);

    Pinroll::boot(new NativePathResolver($root));

    expect(ConnectService::isSetupComplete('production', 'ftp'))->toBeFalse();

    @unlink($root . '/pinroll/pinroll.config.php');
    @rmdir($root . '/pinroll');
    @rmdir($root);
});

test('connect setup is incomplete when gate url is missing', function () {
    $root = sys_get_temp_dir() . '/pinroll-connect-gate-' . uniqid('', true);
    mkdir($root . '/pinroll', 0755, true);
    file_put_contents($root . '/pinroll/pinroll.config.php', <<<'PHP'
<?php

return [
    'hosts' => [
        'production' => [
            'deploy_path' => 'public_html',
            'via' => 'ftp',
            'ftp' => [
                'host' => 'ftp.example.com',
                'user' => 'deploy',
                'password' => 'pass',
            ],
        ],
    ],
];
PHP);

    Pinroll::boot(new NativePathResolver($root));

    expect(ConnectService::isSetupComplete('production', 'ftp'))->toBeFalse();

    @unlink($root . '/pinroll/pinroll.config.php');
    @rmdir($root . '/pinroll');
    @rmdir($root);
});
