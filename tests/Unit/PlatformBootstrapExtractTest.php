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

test('app pinx archive is not treated as platform zip', function () {
    if (!class_exists(ZipArchive::class)) {
        test()->markTestSkipped('ZipArchive extension not available');
    }

    $tmp = sys_get_temp_dir() . '/pinroll-pinx-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);
    $archive = $tmp . '/com_pinoox_manager.pinx';

    $zip = new ZipArchive();
    expect($zip->open($archive, ZipArchive::CREATE))->toBeTrue();
    $zip->addFromString('manifest.json', json_encode([
        'format' => 'pinx',
        'type' => 'app',
        'package' => 'com_pinoox_manager',
    ], JSON_THROW_ON_ERROR));
    $zip->addFromString('payload/app.php', "<?php return ['package' => 'com_pinoox_manager'];");
    $zip->close();

    expect(pinroll_pincore_is_pinx_package($archive))->toBeTrue();

    if (class_exists(\Pinoox\Component\Package\Pinx\PlatformArchive::class)) {
        expect(\Pinoox\Component\Package\Pinx\PlatformArchive::isPlatformArchive($archive))->toBeFalse();
    }

    @unlink($archive);
    @rmdir($tmp);
});

test('blank-host put zip assembles platform.zip from chunks', function () {
    $tmp = sys_get_temp_dir() . '/pinroll-put-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);

    $payload = str_repeat('Pinoox', 1024);
    $mid = (int) floor(strlen($payload) / 2);
    $hash = hash('sha256', $payload);
    $init = pinroll_put_zip_init($tmp, [
        'filename' => 'platform.zip',
        'size' => strlen($payload),
        'chunk_size' => $mid,
        'file_hash' => $hash,
    ]);
    expect($init['id'])->toStartWith('pbl_');

    pinroll_put_zip_chunk($tmp, [
        'upload_id' => $init['id'],
        'index' => 0,
        'chunk' => substr($payload, 0, $mid),
    ]);
    pinroll_put_zip_chunk($tmp, [
        'upload_id' => $init['id'],
        'index' => 1,
        'chunk' => substr($payload, $mid),
    ]);

    $done = pinroll_put_zip_complete($tmp, [
        'upload_id' => $init['id'],
        'file_hash' => $hash,
    ]);

    expect($done['filename'])->toBe('platform.zip')
        ->and(is_file($tmp . '/platform.zip'))->toBeTrue()
        ->and((string) file_get_contents($tmp . '/platform.zip'))->toBe($payload);

    @unlink($tmp . '/platform.zip');
    if (function_exists('pinroll_remove_directory')) {
        pinroll_remove_directory($tmp . '/storage');
    }
    @rmdir($tmp);
});

test('put zip resume requires matching file hash', function () {
    $tmp = sys_get_temp_dir() . '/pinroll-put-resume-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);

    $old = str_repeat('A', 2048);
    $new = str_repeat('B', 2048);
    $chunkSize = 1024;

    $first = pinroll_put_zip_init($tmp, [
        'filename' => 'platform.zip',
        'size' => strlen($old),
        'chunk_size' => $chunkSize,
        'file_hash' => hash('sha256', $old),
    ]);
    pinroll_put_zip_chunk($tmp, [
        'upload_id' => $first['id'],
        'index' => 0,
        'chunk' => substr($old, 0, $chunkSize),
    ]);

    $mismatch = pinroll_put_zip_init($tmp, [
        'filename' => 'platform.zip',
        'size' => strlen($new),
        'chunk_size' => $chunkSize,
        'file_hash' => hash('sha256', $new),
    ]);
    expect($mismatch['id'])->not->toBe($first['id'])
        ->and($mismatch['resumed'] ?? false)->toBeFalse()
        ->and(is_file(pinroll_put_zip_dir($tmp) . '/' . $first['id'] . '.part'))->toBeFalse();

    pinroll_put_zip_chunk($tmp, [
        'upload_id' => $mismatch['id'],
        'index' => 0,
        'chunk' => substr($new, 0, $chunkSize),
    ]);
    $same = pinroll_put_zip_init($tmp, [
        'filename' => 'platform.zip',
        'size' => strlen($new),
        'chunk_size' => $chunkSize,
        'file_hash' => hash('sha256', $new),
    ]);
    expect($same['id'])->toBe($mismatch['id'])
        ->and($same['resumed'])->toBeTrue()
        ->and($same['received'])->toBe($chunkSize);

    pinroll_put_zip_chunk($tmp, [
        'upload_id' => $same['id'],
        'index' => 1,
        'chunk' => substr($new, $chunkSize),
    ]);
    pinroll_put_zip_complete($tmp, [
        'upload_id' => $same['id'],
        'file_hash' => hash('sha256', $new),
    ]);

    expect((string) file_get_contents($tmp . '/platform.zip'))->toBe($new);

    @unlink($tmp . '/platform.zip');
    if (function_exists('pinroll_remove_directory')) {
        pinroll_remove_directory($tmp . '/storage');
    }
    @rmdir($tmp);
});

test('put zip chunk retry is idempotent and checksum mismatch discards leftover', function () {
    $tmp = sys_get_temp_dir() . '/pinroll-put-retry-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);

    $payload = str_repeat('C', 2048);
    $chunkSize = 1024;
    $init = pinroll_put_zip_init($tmp, [
        'filename' => 'platform.zip',
        'size' => strlen($payload),
        'chunk_size' => $chunkSize,
        'file_hash' => hash('sha256', $payload),
    ]);

    pinroll_put_zip_chunk($tmp, [
        'upload_id' => $init['id'],
        'index' => 0,
        'chunk' => substr($payload, 0, $chunkSize),
    ]);
    pinroll_put_zip_chunk($tmp, [
        'upload_id' => $init['id'],
        'index' => 0,
        'chunk' => substr($payload, 0, $chunkSize),
    ]);
    pinroll_put_zip_chunk($tmp, [
        'upload_id' => $init['id'],
        'index' => 1,
        'chunk' => substr($payload, $chunkSize),
    ]);

    expect(filesize(pinroll_put_zip_dir($tmp) . '/' . $init['id'] . '.part'))->toBe(strlen($payload));

    expect(fn () => pinroll_put_zip_complete($tmp, [
        'upload_id' => $init['id'],
        'file_hash' => hash('sha256', 'wrong'),
    ]))->toThrow(RuntimeException::class, 'checksum');

    expect(is_file(pinroll_put_zip_dir($tmp) . '/' . $init['id'] . '.part'))->toBeFalse()
        ->and(is_file(pinroll_put_zip_dir($tmp) . '/' . $init['id'] . '.json'))->toBeFalse();

    if (function_exists('pinroll_remove_directory')) {
        pinroll_remove_directory($tmp . '/storage');
    }
    @rmdir($tmp);
});
