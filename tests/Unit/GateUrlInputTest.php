<?php

use Pinoox\Pinroll\Console\GateUrl;

test('gate url input adds https for domain only', function () {
    expect(GateUrl::normalizeInput('pinoox.com'))
        ->toBe('https://pinoox.com/pingate.php?route=');
    expect(GateUrl::normalizeInput('pinoox.com', 'pinoox3'))
        ->toBe('https://pinoox.com/pinoox3/pingate.php?route=');
});

test('gate url input adds https for url without scheme', function () {
    expect(GateUrl::normalizeInput('pinoox.com/pinoox3/pingate.php'))
        ->toBe('https://pinoox.com/pinoox3/pingate.php?route=');
});

test('gate url input keeps valid https url', function () {
    expect(GateUrl::normalizeInput('https://staging.example.com/app/pingate.php?route='))
        ->toBe('https://staging.example.com/app/pingate.php?route=');
});

test('gate url input rejects invalid host', function () {
    GateUrl::normalizeInput('localhost');
})->throws(InvalidArgumentException::class, 'name.domain');

test('gate url input rejects single label host', function () {
    GateUrl::normalizeInput('myserver');
})->throws(InvalidArgumentException::class, 'name.domain');

test('normalize domain strips scheme and path', function () {
    expect(GateUrl::normalizeDomain('https://www.example.com/path'))
        ->toBe('www.example.com');
});

test('full url is not mixed with hostDir', function () {
    expect(GateUrl::normalizeInput('https://apps.example.com', 'apps'))
        ->toBe('https://apps.example.com/pingate.php?route=');
    expect(GateUrl::normalizeInput('https://example.com/shop', 'ignored'))
        ->toBe('https://example.com/shop/pingate.php?route=');
    expect(GateUrl::normalizeInput('https://pinoox.com/apps.pouyagaranco.ir', 'apps.pouyagaranco.ir'))
        ->toBe('https://pinoox.com/apps.pouyagaranco.ir/pingate.php?route=');
});

