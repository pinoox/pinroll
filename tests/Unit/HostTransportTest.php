<?php

use Pinoox\Pinroll\Host\HostTransport;

test('host transport resolves deploy_path from legacy dir', function () {
    $host = [
        'dir' => 'public_html',
        'via' => 'ftp',
        'ftp' => ['host' => 'ftp.example.com', 'user' => 'u', 'password' => 'p'],
    ];

    $resolved = HostTransport::resolve($host);

    expect($resolved['deploy_path'])->toBe('public_html')
        ->and($resolved['dir'])->toBe('public_html')
        ->and($resolved['host'])->toBe('ftp.example.com');
});

test('host transport prefers hostname over ftp host', function () {
    $host = [
        'deploy_path' => 'public_html',
        'via' => 'ftp',
        'hostname' => 'deploy.example.com',
        'ftp' => ['host' => 'ftp.example.com', 'user' => 'u', 'password' => 'p'],
    ];

    $resolved = HostTransport::resolve($host);

    expect($resolved['hostname'])->toBe('deploy.example.com')
        ->and($resolved['host'])->toBe('deploy.example.com');
});
