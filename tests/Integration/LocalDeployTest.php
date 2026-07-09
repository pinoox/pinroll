<?php

use Pinoox\Pinroll\Console\DeployRunner;
use Pinoox\Pinroll\Console\SampleConfig;
use Pinoox\Pinroll\Tests\Support\ProjectFixture;

test('local deploy builds, transports, and commits in sandbox', function () {
    $fixture = new ProjectFixture();
    $incoming = $fixture->incomingDir();

    $fixture->writeConfig([
        'local' => [
            'transport' => 'local',
            'path' => $incoming,
            'apps' => ['com_test_app'],
        ],
    ]);
    $fixture->writeEmptyBundle();

    $result = (new DeployRunner($fixture->root))->deploy('local', ['bundle' => 'test-empty']);

    expect($result['status'])->toBe('committed');
    expect($result['target'])->toBe('local');
    expect($result['bundle'])->toBe('test-empty');

    $archives = glob($incoming . '/*') ?: [];
    expect($archives)->not->toBeEmpty();

    $history = (new DeployRunner($fixture->root))->history();
    expect($history)->not->toBeEmpty();

    $fixture->cleanup();
});

test('deploy runner build creates manifest and archive', function () {
    $fixture = new ProjectFixture();
    $fixture->writeConfig(['local' => ['transport' => 'local', 'bundle' => 'test-empty']]);
    $fixture->writeEmptyBundle();

    $build = (new DeployRunner($fixture->root))->build('test-empty');

    expect($build['archive'])->toBeString();
    expect(is_file($build['archive']))->toBeTrue();
    expect($build['manifest']['deploy_id'] ?? null)->not->toBeEmpty();

    $fixture->cleanup();
});
