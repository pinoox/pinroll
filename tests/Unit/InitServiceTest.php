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
        ->and(str_replace('\\', '/', $result['config']))->toEndWith('.pinoox/pinroll.config.php')
        ->and($result['env_keys'])->toContain(ConfigWriter::envKeyFor('myconnect', 'host', 'ftp'))
        ->and($result['env_keys'])->toContain(ConfigWriter::envKeyFor('myconnect', 'url', 'pinion'));

    $config = file_get_contents($result['config']);
    expect($config)->toContain("'myconnect' => [")
        ->and($config)->toContain("'default_host' => 'myconnect'")
        ->and($config)->toContain("'site'")
        ->and($config)->toContain("'token'")
        ->and($config)->toContain('vendor/pinoox/pinroll/config/pinroll.php')
        ->and($config)->not->toContain("'production' => [")
        ->and($config)->not->toMatch("/^    'provision' => \[/m");

    $env = file_get_contents($root . '/.env');
    expect($env)->toBe("APP_KEY=test\n")
        ->and($result['env_created'])->toBe([]);

    @unlink($result['config']);
    @rmdir($root . '/.pinoox');
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

test('init adds named host into existing config without rewriting others', function () {
    $root = sys_get_temp_dir() . '/pinroll-init-merge-' . uniqid('', true);
    mkdir($root . '/pinroll', 0755, true);
    file_put_contents($root . '/.env', "APP_KEY=test\n");
    file_put_contents($root . '/pinroll/pinroll.config.php', <<<'PHP'
<?php

return [
    'default_host' => 'poy',
    'keep' => 3,
    'store' => 'remote',
    'auto_clean' => true,
    'hosts' => [
        'poy' => [
            'deploy_path' => 'apps',
            'via' => 'ftp',
            'gate' => [
                'url' => env('PINROLL_POY_URL', ''),
                'token' => env('PINROLL_POY_TOKEN', ''),
            ],
            'ftp' => [
                'host' => env('PINROLL_POY_HOST', ''),
                'user' => env('PINROLL_POY_USER', ''),
                'password' => env('PINROLL_POY_PASSWORD', ''),
            ],
        ],
    ],
];
PHP);

    $result = (new InitService($root))->run('poy2');

    $config = file_get_contents($result['config']);
    expect($config)->toContain("'poy' => [")
        ->and($config)->toContain("'poy2' => [")
        ->and($config)->toContain("env('PINROLL_POY_HOST'")
        ->and($config)->toContain("'site'")
        ->and($config)->toContain("'default_host' => 'poy'");

    $env = file_get_contents($root . '/.env');
    expect($env)->toBe("APP_KEY=test\n")
        ->and($env)->not->toContain('PINROLL_POY2_HOST=');

    @unlink($result['config']);
    @rmdir($root . '/pinroll');
    @unlink($root . '/.env');
    @rmdir($root);
});

test('init production host does not write PINROLL_* stubs into .env', function () {
    $root = sys_get_temp_dir() . '/pinroll-init-prod-' . uniqid('', true);
    mkdir($root, 0755, true);
    file_put_contents($root . '/.env', "APP_KEY=test\n");

    $result = (new InitService($root))->run('production', force: true);

    expect(str_replace('\\', '/', $result['config']))->toEndWith('.pinoox/pinroll.config.php');

    $env = file_get_contents($root . '/.env');
    expect($env)->toBe("APP_KEY=test\n")
        ->and($result['env_created'])->toBe([]);

    @unlink($result['config']);
    @rmdir($root . '/.pinoox');
    @unlink($root . '/.env');
    @rmdir($root);
});

