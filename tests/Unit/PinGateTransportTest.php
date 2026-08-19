<?php

use Pinoox\Pinroll\Target\PinGateTransport;

test('pin gate transport rejects missing or placeholder ca files', function () {
    expect(PinGateTransport::isUsableCaFile(''))->toBeFalse();
    expect(PinGateTransport::isUsableCaFile('MAMP_BASEDIR_MAMPbin\\apache\\bin\\cacert.pem'))->toBeFalse();
    expect(PinGateTransport::isUsableCaFile(sys_get_temp_dir() . '/no-such-ca.pem'))->toBeFalse();
});

test('pin gate transport accepts a real readable ca bundle', function () {
    $file = tempnam(sys_get_temp_dir(), 'ca');
    file_put_contents($file, str_repeat("-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n", 40));

    expect(PinGateTransport::isUsableCaFile($file))->toBeTrue();

    unlink($file);
});
