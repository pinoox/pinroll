<?php

use Pinoox\Pinroll\Console\DeployAppSelector;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

function pinrollTempPinxRoot(string $package = 'com_acme_shop'): string
{
    $root = sys_get_temp_dir() . '/pinroll-pinx-' . uniqid('', true);
    mkdir($root, 0755, true);
    file_put_contents($root . '/app.php', "<?php\nreturn ['package' => '{$package}', 'enable' => true];\n");

    return $root;
}

function pinrollSelectorIo(): SymfonyStyle
{
    return new SymfonyStyle(new ArrayInput([]), new NullOutput());
}

function pinrollSelectorInput(bool $interactive = false): ArrayInput
{
    $definition = new InputDefinition([
        new InputOption('app', null, InputOption::VALUE_REQUIRED),
        new InputOption('apps', null, InputOption::VALUE_REQUIRED),
        new InputOption('full', null, InputOption::VALUE_NONE),
    ]);
    $input = new ArrayInput([], $definition);
    $input->setInteractive($interactive);

    return $input;
}

test('pinx-root deploy ignores hosts.apps and uses app.php package', function () {
    $root = pinrollTempPinxRoot('com_acme_shop');
    $io = pinrollSelectorIo();
    $input = pinrollSelectorInput();

    $apps = DeployAppSelector::resolve($io, $input, [
        'apps' => ['com_pinoox_manager', 'com_pinoox_welcome'],
    ], [], $root);

    expect($apps)->toBe(['com_acme_shop']);

    array_map('unlink', glob($root . '/*') ?: []);
    @rmdir($root);
});

test('pinx-root --app override still wins', function () {
    $root = pinrollTempPinxRoot('com_acme_shop');
    $io = pinrollSelectorIo();
    $input = pinrollSelectorInput();

    $apps = DeployAppSelector::resolve($io, $input, [
        'apps' => ['com_pinoox_manager'],
    ], ['app' => 'com_other_app'], $root);

    expect($apps)->toBe(['com_other_app']);

    array_map('unlink', glob($root . '/*') ?: []);
    @rmdir($root);
});

test('fromPinxRoot returns empty when app.php is missing', function () {
    $root = sys_get_temp_dir() . '/pinroll-platform-' . uniqid('', true);
    mkdir($root, 0755, true);

    expect(DeployAppSelector::fromPinxRoot($root))->toBe([]);

    @rmdir($root);
});
