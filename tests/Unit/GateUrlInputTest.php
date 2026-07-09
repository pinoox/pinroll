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
