<?php

use Pinoox\Pinroll\Release\ReleaseManifest;
use Pinoox\Pinroll\Rollout\RolloutSession;
use Pinoox\Pinroll\Support\Config;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Transport\LocalTransport;
use Pinoox\Pinroll\Tests\Support\ProjectFixture;

test('rollout session persists steps to storage', function () {
    $fixture = new ProjectFixture();
    $paths = new NativePathResolver($fixture->root);
    $config = new Config($paths, ['storage_path' => $fixture->root . '/storage']);

    $session = RolloutSession::create($config, 'local', 'test-empty', 'local');
    $session->addStep('build', 'ok', 'Built release');
    $session->markCommitted();

    $loaded = RolloutSession::load($config, $session->id());

    expect($loaded)->not->toBeNull();
    expect($loaded->status())->toBe('committed');
    expect($loaded->toArray()['steps'])->toHaveCount(1);

    $fixture->cleanup();
});

test('local transport copies archive and manifest', function () {
    $fixture = new ProjectFixture();
    $paths = new NativePathResolver($fixture->root);
    $config = new Config($paths, ['storage_path' => $fixture->root . '/storage']);
    $incoming = $fixture->incomingDir();

    $archive = $fixture->root . '/release.tar';
    file_put_contents($archive, 'pinroll-test-payload');

    $manifest = ReleaseManifest::fromArray([
        'deploy_id' => 'deploy_test_1',
        'archive_path' => $archive,
        'files_checksum' => 'sha256:' . hash('sha256', 'pinroll-test-payload'),
    ]);

    $session = RolloutSession::create($config, 'local', 'test-empty', 'local');
    (new LocalTransport($config))->send($archive, $manifest, ['path' => $incoming], $session);

    expect(is_file($incoming . '/release.tar'))->toBeTrue();
    expect(is_file($incoming . '/deploy_test_1.manifest.json'))->toBeTrue();

    $fixture->cleanup();
});
