<?php

require_once dirname(__DIR__, 2) . '/resources/pingate/bootstrap.php';

test('platform zip helper rejects zip slip and preserves pingate.php', function () {
    $tmp = sys_get_temp_dir() . '/pinroll-platform-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);
    file_put_contents($tmp . '/pingate.php', '<?php return [];');

    $zipPath = $tmp . '/platform.zip';
    $zip = new ZipArchive();
    expect($zip->open($zipPath, ZipArchive::CREATE))->toBeTrue();
    $zip->addFromString('../evil.php', 'hack');
    $zip->addFromString('index.php', '<?php');
    $zip->addFromString('vendor/autoload.php', '<?php');
    $zip->close();

    $opened = new ZipArchive();
    $opened->open($zipPath);
    $inspect = pinroll_platform_zip_is_safe($opened, $tmp);
    $opened->close();

    expect($inspect)->toBeString()
        ->and($inspect)->toContain('traversal');

    @unlink($zipPath);
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('index.php', "<?php echo 'ok';");
    $zip->addFromString('vendor/autoload.php', '<?php');
    $zip->addFromString('pingate.php', 'SHOULD_NOT_OVERWRITE');
    $zip->addFromString('apps/com_demo/app.php', '<?php return [];');
    $zip->close();

    $opened = new ZipArchive();
    $opened->open($zipPath);
    expect(pinroll_platform_zip_is_safe($opened, $tmp))->toBeTrue();
    $extract = pinroll_platform_zip_extract_safe($opened, $tmp);
    $opened->close();

    expect($extract)->toBeTrue()
        ->and(file_get_contents($tmp . '/pingate.php'))->toBe('<?php return [];')
        ->and(is_file($tmp . '/index.php'))->toBeTrue()
        ->and(is_file($tmp . '/vendor/autoload.php'))->toBeTrue()
        ->and(is_file($tmp . '/apps/com_demo/app.php'))->toBeTrue();

    @unlink($tmp . '/apps/com_demo/app.php');
    @rmdir($tmp . '/apps/com_demo');
    @rmdir($tmp . '/apps');
    pinroll_remove_directory($tmp . '/vendor');
    @unlink($tmp . '/index.php');
    @unlink($tmp . '/pingate.php');
    @unlink($zipPath);
    @rmdir($tmp);
});

test('provision payload validation rejects short names and missing db', function () {
    $errors = pinroll_validate_provision_payload(
        ['host' => '', 'database' => 'db', 'username' => 'root'],
        ['fname' => 'Al', 'lname' => 'Lo', 'email' => 'x', 'username' => 'ab', 'password' => '1'],
        'de',
    );

    expect($errors)->not->toBeEmpty();
});
