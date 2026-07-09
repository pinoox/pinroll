<?php

namespace Pinoox\Pinroll\Tests\Support;

use Pinoox\Pinroll\Console\ProjectInitializer;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ProjectFixture
{
    public readonly string $root;

    public function __construct(?string $root = null)
    {
        $this->root = $root ?? sys_get_temp_dir() . '/pinroll-project-' . uniqid('', true);

        if (!is_dir($this->root)) {
            mkdir($this->root, 0755, true);
        }

        if (!is_dir($this->root . '/storage')) {
            mkdir($this->root . '/storage', 0755, true);
        }
    }

    public function scaffoldDefaults(): void
    {
        (new ProjectInitializer($this->root, true))->init();
    }

  /**
     * @param array<string, mixed> $targets
     */
    public function writeConfig(array $targets): void
    {
        $dir = $this->root . '/pinroll';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $export = var_export(['targets' => $targets], true);
        file_put_contents(
            $dir . '/pinroll.config.php',
            "<?php\n\nreturn {$export};\n",
        );
    }

    public function writeEmptyBundle(): void
    {
        $dir = $this->root . '/pinroll/bundles';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($dir . '/test-empty.php', <<<'PHP'
<?php

return [
    'name' => 'test-empty',
    'scope' => 'app',
    'build' => [],
    'depends_check' => false,
];
PHP
        );
    }

    public function incomingDir(): string
    {
        return $this->root . '/storage/pinroll/incoming';
    }

    public function cleanup(): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($this->root);
    }
}
