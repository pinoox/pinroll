<?php

use Pinoox\Pinroll\Target\TargetChecker;
use Pinoox\Pinroll\Tests\Support\ProjectFixture;

test('local target check passes for writable incoming path', function () {
    $fixture = new ProjectFixture();
    $fixture->writeConfig([
        'local' => [
            'transport' => 'local',
            'path' => $fixture->incomingDir(),
            'bundle' => 'test-empty',
        ],
    ]);

    $result = (new TargetChecker($fixture->root))->check('local');

    expect($result['ok'])->toBeTrue();
    expect($result['transport'])->toBe('local');

    $fixture->cleanup();
});

test('pinion target check fails when gate_url is missing', function () {
    $fixture = new ProjectFixture();
    $fixture->writeConfig([
        'production' => [
            'transport' => 'pinion',
            'gate_url' => '',
            'token' => '',
            'bundle' => 'platform-full',
        ],
    ]);

    $result = (new TargetChecker($fixture->root))->check('production');

    expect($result['ok'])->toBeFalse();

    $fixture->cleanup();
});
