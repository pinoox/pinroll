<?php

use Pinoox\Pinroll\Console\BundleInputParser;
use Pinoox\Pinroll\Console\GateUrl;
use Pinoox\Pinroll\Console\SampleConfig;

test('gate url is built from domain only', function () {
    expect(GateUrl::fromDomain('pinoox.com'))
        ->toBe('https://pinoox.com/pingate.php?route=');
    expect(GateUrl::fromDomain('https://staging.pinoox.com/', 'pinoox3'))
        ->toBe('https://staging.pinoox.com/pinoox3/pingate.php?route=');
});

test('bundle input parser maps platform and pincore aliases', function () {
    expect(BundleInputParser::parse('platform'))->toBe(['bundle' => 'platform-full']);
    expect(BundleInputParser::parse('pincore'))->toBe(['bundle' => 'platform-core']);
});

test('bundle input parser accepts single and multiple app packages', function () {
    expect(BundleInputParser::parse('com_pinoox_developer'))->toBe([
        'bundle' => 'single-app',
        'package' => 'com_pinoox_developer',
    ]);

    expect(BundleInputParser::parse('com_a, com_b'))->toBe([
        'bundle' => 'single-app',
        'packages' => ['com_a', 'com_b'],
    ]);
});

test('sample config includes production target with ftp block', function () {
    $targets = SampleConfig::targets();

    expect(array_keys($targets))->toBe(['production']);
    expect($targets['production']['via'])->toBe('ftp');
    expect($targets['production']['ftp'])->toBeArray();
});
