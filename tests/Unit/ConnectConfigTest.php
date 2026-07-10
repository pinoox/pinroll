<?php

use Pinoox\Pinroll\Console\ConnectConfig;

test('connect is configured when deploy path gate url and ftp creds exist', function () {
    expect(ConnectConfig::isConfigured([
        'deploy_path' => 'public_html/pinoox3',
        'gate_url' => 'https://example.com/pinoox3/pingate.php?route=',
        'host' => 'ftp.example.com',
        'user' => 'deploy',
    ], 'ftp'))->toBeTrue();
});

test('connect is not configured without deploy path', function () {
    expect(ConnectConfig::isConfigured([
        'gate_url' => 'https://example.com/pingate.php?route=',
        'host' => 'ftp.example.com',
        'user' => 'deploy',
    ], 'ftp'))->toBeFalse();
});

test('connect is not configured without gate url', function () {
    expect(ConnectConfig::isConfigured([
        'deploy_path' => 'public_html',
        'host' => 'ftp.example.com',
        'user' => 'deploy',
    ], 'ftp'))->toBeFalse();
});

test('connect is not configured without ftp credentials', function () {
    expect(ConnectConfig::isConfigured([
        'deploy_path' => 'public_html',
        'gate_url' => 'https://example.com/pingate.php?route=',
    ], 'ftp'))->toBeFalse();
});

test('pinion connect only requires deploy path and gate url', function () {
    expect(ConnectConfig::isConfigured([
        'deploy_path' => 'public_html',
        'gate_url' => 'https://example.com/pingate.php?route=',
    ], 'pinion'))->toBeTrue();
});
