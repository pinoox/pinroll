<?php

use Pinoox\Pinroll\Target\PinGateProbe;

test('pingate probe accepts valid status json', function () {
    $body = json_encode([
        'success' => true,
        'data' => ['status' => 'unknown'],
    ], JSON_THROW_ON_ERROR);

    $result = PinGateProbe::validateStatusResponse(200, $body, 'pinoox3');

    expect($result['ok'])->toBeTrue();
    expect($result['deployed'])->toBeTrue();
});

test('pingate probe rejects html homepage as not deployed', function () {
    $result = PinGateProbe::validateStatusResponse(200, '<html><body>Welcome</body></html>', 'pinoox3');

    expect($result['ok'])->toBeFalse();
    expect($result['deployed'])->toBeFalse();
    expect($result['message'])->toContain('htaccess');
});

test('pingate probe extracts php warning from xdebug html', function () {
    $html = "<br /><font>Warning: require(/home/user/public_html/pinoox3/vendor/composer/../phpunit/phpunit/src/Framework/Assert/Functions.php): Failed to open stream: No such file or directory in /home/user/public_html/pinoox3/vendor/composer/autoload_real.php on line 41</font>";

    $result = PinGateProbe::validateStatusResponse(200, $html, 'pinoox3');

    expect($result['ok'])->toBeFalse();
    expect($result['message'])->toContain('Failed to open stream');
    expect($result['message'])->toContain('pinroll:vendor');
});

test('pingate probe rejects 404 with deploy hint', function () {
    $result = PinGateProbe::validateStatusResponse(404, 'Not Found', 'pinoox3');

    expect($result['ok'])->toBeFalse();
    expect($result['message'])->toContain('Not found (404)');
    expect($result['message'])->toContain('pinroll:gate');
});

test('pingate probe detects invalid gate url route', function () {
    $body = json_encode(['success' => false, 'error' => 'Unknown PinGate route: foo'], JSON_THROW_ON_ERROR);

    $result = PinGateProbe::validateStatusResponse(404, $body, 'pinoox3');

    expect($result['ok'])->toBeFalse();
    expect($result['message'])->toContain('Wrong gate_url');
});

test('pingate probe surfaces real json error for 503', function () {
    $body = json_encode([
        'success' => false,
        'error' => 'Pinroll not available. On your local machine run: php pinoox pinroll:vendor — upload vendor.zip and extract next to pingate.php (merge into vendor/).',
    ], JSON_THROW_ON_ERROR);

    $result = PinGateProbe::validateStatusResponse(503, $body, '');

    expect($result['ok'])->toBeFalse();
    expect($result['message'])->toContain('Pinroll not available');
    expect($result['message'])->toContain('pinroll:vendor');
    expect($result['message'])->not->toContain('HTTP 503');
});
