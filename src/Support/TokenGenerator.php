<?php

namespace Pinoox\Pinroll\Support;

final class TokenGenerator
{
    public static function token(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function deployId(): string
    {
        return date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Host files store sha256(plain). Clients may send the plaintext or that hash
     * (copied from storage/pinroll/tokens/{label}.php into PINROLL_TOKEN).
     */
    public static function matchesStoredHash(string $token, string $storedHash): bool
    {
        $token = strtolower(trim($token));
        $storedHash = strtolower(trim($storedHash));
        if ($token === '' || $storedHash === '') {
            return false;
        }
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1 || preg_match('/^[a-f0-9]{64}$/', $storedHash) !== 1) {
            return false;
        }

        return hash_equals($storedHash, $token)
            || hash_equals($storedHash, self::hashToken($token));
    }
}
