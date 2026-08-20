<?php

use Pinoox\Pinroll\Console\OverlayWriter;
use Pinoox\Pinroll\Host\HostGate;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\NativePathResolver;

test('overlay writer creates a short stub not a library dump', function () {
    $root = sys_get_temp_dir() . '/pinroll-overlay-' . uniqid('', true);
    $path = $root . '/.pinoox/pinroll.config.php';

    OverlayWriter::writeStub($path, 'production');

    $contents = (string) file_get_contents($path);
    expect($contents)->toContain("'default_host' => 'production'")
        ->and($contents)->toContain("'site'")
        ->and($contents)->toContain("// 'keep' => 3")
        ->and($contents)->toContain("// 'provision' => [")
        ->and($contents)->toContain("// 'build' => [")
        ->and($contents)->toContain("// 'web_path' => ''")
        ->and($contents)->toContain("// 'ssh' => [")
        ->and($contents)->not->toMatch("/^    'provision' => \[/m")
        ->and($contents)->not->toContain("'storage_path'");

    @unlink($path);
    @rmdir($root . '/.pinoox');
    @rmdir($root);
});

test('overlay writer patches site and token without rewriting unrelated keys', function () {
    $root = sys_get_temp_dir() . '/pinroll-overlay-patch-' . uniqid('', true);
    $path = $root . '/.pinoox/pinroll.config.php';
    mkdir(dirname($path), 0755, true);
    file_put_contents($path, <<<'PHP'
<?php

return [
    'keep' => 7,
    'hosts' => [
        'production' => [
            'deploy_path' => 'apps',
            'via' => 'ftp',
            'apps' => ['com_pinoox_shop'],
            'gate' => [
                'site' => '',
                'token' => '',
            ],
        ],
    ],
];
PHP);

    OverlayWriter::patch($path, 'production', [
        'gate' => [
            'site' => 'https://pinoox.com',
            'token' => 'shared-token',
        ],
        'ftp' => [
            'password' => 'secret-pass',
        ],
    ]);

    $contents = (string) file_get_contents($path);
    expect($contents)->toContain("'keep' => 7")
        ->and($contents)->toContain("'apps' => ['com_pinoox_shop']")
        ->and($contents)->toContain("'deploy_path' => 'apps'")
        ->and($contents)->toContain("'site' => 'https://pinoox.com'")
        ->and($contents)->toContain("'token' => 'shared-token'")
        ->and($contents)->toContain("'password' => 'secret-pass'");

    @unlink($path);
    @rmdir($root . '/.pinoox');
    @rmdir($root);
});

test('persist gate writes overlay and does not rewrite env when overlay exists', function () {
    $root = sys_get_temp_dir() . '/pinroll-persist-' . uniqid('', true);
    mkdir($root . '/.pinoox', 0755, true);
    $overlay = $root . '/.pinoox/pinroll.config.php';
    $env = $root . '/.env';
    file_put_contents($overlay, <<<'PHP'
<?php

return [
    'hosts' => [
        'production' => [
            'via' => 'ftp',
            'gate' => [
                'site' => '',
                'token' => 'old-token',
            ],
        ],
    ],
];
PHP);
    file_put_contents($env, "PINROLL_URL=https://old.example.com/pingate.php?route=\nPINROLL_TOKEN=old-token\n");

    $written = OverlayWriter::persistGate($root, 'production', 'https://pinoox.com', 'new-token');

    expect(str_replace('\\', '/', $written))->toEndWith('.pinoox/pinroll.config.php');

    $overlayContents = (string) file_get_contents($overlay);
    $envContents = (string) file_get_contents($env);

    expect($overlayContents)->toContain("'site' => 'https://pinoox.com'")
        ->and($overlayContents)->toContain("'token' => 'new-token'")
        ->and($envContents)->toContain('PINROLL_URL=https://old.example.com/pingate.php?route=')
        ->and($envContents)->toContain('PINROLL_TOKEN=old-token')
        ->and($envContents)->not->toContain('new-token');

    @unlink($overlay);
    @unlink($env);
    @rmdir($root . '/.pinoox');
    @rmdir($root);
});

test('host gate credentials expand site origin and accept legacy full urls', function () {
    $fromSite = HostGate::credentials([
        'gate' => [
            'site' => 'https://pinoox.com',
            'token' => 'tok',
        ],
    ]);
    $fromUrl = HostGate::credentials([
        'gate' => [
            'url' => 'https://pinoox.com/shop/pingate.php?route=',
            'token' => 'tok',
        ],
    ]);

    expect($fromSite['site'])->toBe('https://pinoox.com')
        ->and($fromSite['url'])->toBe('https://pinoox.com/pingate.php?route=')
        ->and($fromUrl['site'])->toBe('https://pinoox.com/shop')
        ->and($fromUrl['url'])->toBe('https://pinoox.com/shop/pingate.php?route=');
});

test('resolved production host prefers overlay token without dumping config', function () {
    $root = sys_get_temp_dir() . '/pinroll-resolved-' . uniqid('', true);
    mkdir($root . '/.pinoox', 0755, true);
    file_put_contents($root . '/.pinoox/pinroll.config.php', <<<'PHP'
<?php

return [
    'hosts' => [
        'production' => [
            'gate' => [
                'site' => 'https://shop.example.com',
                'token' => 'super-secret-token',
            ],
        ],
    ],
];
PHP);

    Pinroll::boot(new NativePathResolver($root));
    $row = Pinoox\Pinroll\Host\ResolvedConfig::forHost('production');

    expect($row['site'])->toBe('https://shop.example.com')
        ->and($row['gate_url'])->toBe('https://shop.example.com/pingate.php?route=')
        ->and($row['via'])->toBe('ftp')
        ->and($row['deploy_path'])->toBe('public_html')
        ->and($row['token_redacted'])->toContain('ken')
        ->and($row['token_redacted'])->not->toContain('super-secret');

    @unlink($root . '/.pinoox/pinroll.config.php');
    @rmdir($root . '/.pinoox');
    @rmdir($root);
});
