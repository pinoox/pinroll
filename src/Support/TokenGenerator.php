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
}
