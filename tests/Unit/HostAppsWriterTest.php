<?php

use Pinoox\Pinroll\Console\ConfigWriter;

test('setHostApps replaces commented apps block with active apps', function () {
    $path = sys_get_temp_dir() . '/pinroll-apps-' . uniqid('', true) . '.php';
    file_put_contents($path, <<<'PHP'
<?php

return [
    'hosts' => [
        'production' => [
            'deploy_path' => 'public_html',
            'via' => 'ftp',

            // Default app packages for push/install (required — pinroll:push will prompt if omitted)
            // 'apps' => ['com_pinoox_account'],

            'gate' => [],
        ],
    ],
];
PHP);

    ConfigWriter::setHostApps($path, 'production', ['com_pinoox_manager', 'com_pinoox_account']);

    $contents = file_get_contents($path);
    expect($contents)
        ->toContain("'apps' => ['com_pinoox_manager', 'com_pinoox_account']")
        ->not->toContain("// 'apps' => ['com_pinoox_account']");

    @unlink($path);
});

test('setHostApps clears apps back to commented placeholder', function () {
    $path = sys_get_temp_dir() . '/pinroll-apps-clear-' . uniqid('', true) . '.php';
    file_put_contents($path, <<<'PHP'
<?php

return [
    'hosts' => [
        'production' => [
            'via' => 'ftp',
            // Default app packages for push/install on this host
            'apps' => ['com_pinoox_manager'],
        ],
    ],
];
PHP);

    ConfigWriter::setHostApps($path, 'production', null);

    $contents = file_get_contents($path);
    expect($contents)
        ->toContain('pinroll:apps')
        ->toContain("// 'apps' => ['com_pinoox_account']")
        ->not->toContain("'apps' => ['com_pinoox_manager']");

    @unlink($path);
});

test('setHostApps inserts apps after via when block is missing', function () {
    $path = sys_get_temp_dir() . '/pinroll-apps-insert-' . uniqid('', true) . '.php';
    file_put_contents($path, <<<'PHP'
<?php

return [
    'hosts' => [
        'staging' => [
            'deploy_path' => 'public_html',
            'via' => 'ftp',
            'gate' => [],
        ],
    ],
];
PHP);

    ConfigWriter::setHostApps($path, 'staging', ['com_test_app']);

    $contents = file_get_contents($path);
    expect($contents)
        ->toContain("'via' => 'ftp'")
        ->toContain("'apps' => ['com_test_app']");

    @unlink($path);
});
