<?php

use Pinoox\Pinroll\Console\ProvisionSettings;
use Pinoox\Pinroll\PinGate\HostPostInstall;

test('provision settings fill default admin user when fields are empty', function () {
    $settings = ProvisionSettings::resolve([
        'provision' => [
            'db' => [
                'host' => 'localhost',
                'database' => 'pinoox',
                'username' => 'root',
            ],
            'user' => [
                'fname' => '',
                'email' => '',
            ],
        ],
    ]);

    expect($settings['user'])->toBe([
        'fname' => 'support',
        'lname' => 'pinoox',
        'email' => 'info@pinoox.com',
        'username' => 'admin',
        'password' => '123456',
    ]);
    expect(ProvisionSettings::validate($settings))->toBe([]);
});

test('provision settings keep explicit admin overrides', function () {
    $settings = ProvisionSettings::resolve([
        'provision' => [
            'user' => [
                'fname' => 'Ada',
                'lname' => 'Lovelace',
                'email' => 'ada@example.com',
                'username' => 'ada',
                'password' => 'secret1',
            ],
        ],
    ], [
        'user' => ['username' => 'rootadmin'],
    ]);

    expect($settings['user']['fname'])->toBe('Ada')
        ->and($settings['user']['username'])->toBe('rootadmin')
        ->and($settings['user']['email'])->toBe('ada@example.com');
});

test('host post install writes welcome and manager routes from installer config', function () {
    $root = sys_get_temp_dir() . '/pinroll-post-install-' . uniqid('', true);
    mkdir($root . '/apps/com_pinoox_installer/config', 0755, true);
    mkdir($root . '/platform', 0755, true);
    file_put_contents(
        $root . '/apps/com_pinoox_installer/config/app.config.php',
        "<?php\nreturn ['/' => 'com_pinoox_welcome', '/manager' => 'com_pinoox_manager'];\n",
    );
    file_put_contents(
        $root . '/platform/app-router.config.php',
        "<?php\nreturn ['/' => 'com_pinoox_installer'];\n",
    );

    $result = HostPostInstall::apply($root);

    expect($result['routes']['/'])->toBe('com_pinoox_welcome')
        ->and($result['routes']['/manager'])->toBe('com_pinoox_manager')
        ->and($result['installer_disabled'])->toBeTrue()
        ->and($result['router_written'])->toBeTrue();

    $router = include $root . '/platform/app-router.config.php';
    expect($router['/'])->toBe('com_pinoox_welcome')
        ->and($router['/manager'])->toBe('com_pinoox_manager');

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($root);
});
