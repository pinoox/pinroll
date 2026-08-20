<?php

use Pinoox\Pinroll\PinGate\GateTokenRegistry;
use Pinoox\Pinroll\Support\TokenGenerator;

it('normalizes token labels', function () {
    expect(GateTokenRegistry::normalizeLabel('Yousef'))->toBe('yousef')
        ->and(GateTokenRegistry::normalizeLabel('ali.dev'))->toBe('ali.dev');
});

it('writes and loads token registry files', function () {
    $root = sys_get_temp_dir() . '/pinroll-token-' . uniqid('', true);
    mkdir($root, 0777, true);

    $plain = TokenGenerator::token();
    $path = GateTokenRegistry::writeTokenFile($root, 'yousef', $plain);

    expect(is_file($path))->toBeTrue()
        ->and(GateTokenRegistry::registryHashes($root))->toHaveCount(1)
        ->and(GateTokenRegistry::verifyPlainToken($plain, $root))->toBeTrue()
        ->and(GateTokenRegistry::verifyPlainToken(TokenGenerator::token(), $root))->toBeFalse();

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($root);
});

it('accepts legacy env and embedded hashes', function () {
    $root = sys_get_temp_dir() . '/pinroll-token-legacy-' . uniqid('', true);
    mkdir($root, 0777, true);

    $plain = TokenGenerator::token();
    file_put_contents($root . '/.env', 'PINROLL_GATE_TOKEN=' . $plain . "\n");

    $hashes = GateTokenRegistry::acceptedHashes($root, ['token_hash' => hash('sha256', 'other')]);
    expect($hashes)->toHaveCount(2)
        ->and(GateTokenRegistry::verifyPlainToken($plain, $root))->toBeTrue();

    unlink($root . '/.env');
    rmdir($root);
});

it('builds host upload path from label', function () {
    expect(GateTokenRegistry::hostUploadPath('yousef'))->toBe('storage/pinroll/tokens/yousef.php');
});

it('resolves default label from configured gate.label', function () {
    expect(GateTokenRegistry::labelFromHost(['gate' => ['label' => 'Ali.Dev']]))->toBe('ali.dev');
});
