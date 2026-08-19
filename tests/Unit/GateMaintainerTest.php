<?php

use Pinoox\Pinroll\Console\GateMaintainer;

test('gate maintainer treats hosting 503 as repairable', function () {
    $ref = new ReflectionClass(GateMaintainer::class);
    $method = $ref->getMethod('isRepairableMessage');
    $method->setAccessible(true);

    expect($method->invoke(null, 'HTTP 503. Run pinroll:gate'))->toBeTrue();
    expect($method->invoke(null, 'Host web server returned 503 for pingate.php'))->toBeTrue();
    expect($method->invoke(null, 'Missing bearer token.'))->toBeTrue();
});
