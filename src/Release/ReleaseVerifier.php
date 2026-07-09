<?php

namespace Pinoox\Pinroll\Release;

use Pinoox\Pinroll\Exception\PinrollException;

final class ReleaseVerifier
{
    public function verify(ReleaseManifest $manifest, ?string $publicKey = null): void
    {
        $archive = $manifest->archivePath();

        if ($archive === '' || !is_file($archive)) {
            throw new PinrollException('Release archive missing.');
        }

        $checksum = $manifest->checksum();
        if ($checksum !== '') {
            $expected = str_starts_with($checksum, 'sha256:') ? substr($checksum, 7) : $checksum;
            $actual = hash_file('sha256', $archive);

            if (!hash_equals($expected, $actual)) {
                throw new PinrollException('Release checksum mismatch.');
            }
        }

        $signature = $manifest->signature();
        if ($signature !== '' && $publicKey !== null && $publicKey !== '') {
            $this->verifySignature($manifest, $publicKey, $signature);
        }
    }

    private function verifySignature(ReleaseManifest $manifest, string $publicKey, string $signature): void
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new PinrollException('Ed25519 verification requires ext-sodium.');
        }

        $sig = str_starts_with($signature, 'ed25519:') ? substr($signature, 8) : $signature;
        $message = $manifest->checksum() . '|' . $manifest->deployId();
        $sigBin = hex2bin($sig);
        $keyBin = hex2bin($publicKey);

        if ($sigBin === false || $keyBin === false) {
            throw new PinrollException('Invalid signature encoding.');
        }

        if (!sodium_crypto_sign_verify_detached($sigBin, $message, $keyBin)) {
            throw new PinrollException('Release signature verification failed.');
        }
    }
}
