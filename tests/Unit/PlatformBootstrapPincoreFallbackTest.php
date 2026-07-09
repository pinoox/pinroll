<?php

use Pinoox\Pinroll\Bridge\PlatformBootstrap;
use Pinoox\Pinroll\Exception\PinrollException;

beforeEach(function () {
    PlatformBootstrap::reset();
    putenv('PINOOX_CORE_PATH');
    unset($_ENV['PINOOX_CORE_PATH'], $_SERVER['PINOOX_CORE_PATH']);
});

test('platform bootstrap falls back to vendor pincore when .pincore sibling is missing', function () {
    if (defined('PINOOX_BASE_PATH') || defined('PINOOX_CORE_PATH')) {
        $this->markTestSkipped('PINOOX_* already defined in this process');
    }

    $root = sys_get_temp_dir() . '/pinroll-pincore-fb-' . uniqid('', true);
    mkdir($root . '/platform/launcher', 0755, true);
    mkdir($root . '/vendor/pinoox/pincore/functions', 0755, true);
    mkdir($root . '/vendor/pinoox/pincore/Portal/App', 0755, true);

    // Minimal launcher core-path (mirrors production fallback behavior).
    file_put_contents($root . '/platform/launcher/core-path.php', <<<'PHP'
<?php
function pinoox_normalize_path(string $path): string { return rtrim(str_replace('\\', '/', $path), '/'); }
function pinoox_is_valid_core_path(string $path): bool {
    $path = pinoox_normalize_path($path);
    return is_file($path . '/functions/base.php');
}
function pinoox_resolve_relative_core_path(string $basePath, string $configuredPath): string {
    $configuredPath = pinoox_normalize_path($configuredPath);
    if (!preg_match('/^[A-Za-z]:\//', $configuredPath) && !str_starts_with($configuredPath, '/')) {
        $configuredPath = pinoox_normalize_path($basePath . '/' . $configuredPath);
    }
    return $configuredPath;
}
function pinoox_resolve_configured_core_path(string $basePath): string {
    $configuredPath = getenv('PINOOX_CORE_PATH') ?: null;
    $configFile = $basePath . '/.pincore';
    if (empty($configuredPath) && is_file($configFile)) {
        $configuredPath = trim((string) file_get_contents($configFile));
    }
    if (!empty($configuredPath)) {
        $resolved = pinoox_resolve_relative_core_path($basePath, $configuredPath);
        if (pinoox_is_valid_core_path($resolved)) {
            return $resolved;
        }
    }
    return pinoox_normalize_path($basePath . '/vendor/pinoox/pincore');
}
defined('PINOOX_BASE_PATH') || define('PINOOX_BASE_PATH', pinoox_normalize_path(dirname(__DIR__, 2)));
defined('PINOOX_CORE_PATH') || define('PINOOX_CORE_PATH', pinoox_resolve_configured_core_path(PINOOX_BASE_PATH) . '/');
PHP);

    file_put_contents($root . '/.pincore', '../pincore3');
    file_put_contents($root . '/vendor/pinoox/pincore/functions/base.php', "<?php\n");
    file_put_contents($root . '/vendor/autoload.php', "<?php\nreturn new class {\n};\n");

    // Stop before full portal boot — only assert core path resolution via ensure's early checks.
    // Provide fake AppEngine/Pinx so ensure can finish after core load.
    file_put_contents($root . '/vendor/pinoox/pincore/Portal/App/AppEngine.php', <<<'PHP'
<?php
namespace Pinoox\Portal\App;
class AppEngine {
    public static function __rebuild(): void {}
}
PHP);
    file_put_contents($root . '/vendor/pinoox/pincore/Portal/Pinx.php', <<<'PHP'
<?php
namespace Pinoox\Portal;
class Pinx {
    public static function __rebuild(): void {}
}
PHP);

    // Autoload stubs
    spl_autoload_register(static function (string $class) use ($root): void {
        $map = [
            'Pinoox\\Portal\\App\\AppEngine' => $root . '/vendor/pinoox/pincore/Portal/App/AppEngine.php',
            'Pinoox\\Portal\\Pinx' => $root . '/vendor/pinoox/pincore/Portal/Pinx.php',
        ];
        if (isset($map[$class]) && is_file($map[$class])) {
            require_once $map[$class];
        }
    });

    PlatformBootstrap::ensure($root);

    expect(rtrim((string) PINOOX_CORE_PATH, '/'))
        ->toBe($root . '/vendor/pinoox/pincore');

    // cleanup
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($root);
});
