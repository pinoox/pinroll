<?php

namespace Pinoox\Pinroll\PinGate;

use Pinoox\Pinroll\Support\TokenGenerator;

/**
 * Per-developer PinGate tokens: storage/pinroll/tokens/{label}.php (hash only on host).
 */
final class GateTokenRegistry
{
    public const TOKENS_DIR = 'storage/pinroll/tokens';

    public static function tokensDir(string $root): string
    {
        return rtrim(str_replace('\\', '/', $root), '/') . '/' . self::TOKENS_DIR;
    }

    public static function tokenFile(string $root, string $label): string
    {
        return self::tokensDir($root) . '/' . self::normalizeLabel($label) . '.php';
    }

    public static function normalizeLabel(string $label): string
    {
        $label = strtolower(trim($label));
        $label = preg_replace('/[^a-z0-9._-]+/', '-', $label) ?? '';
        $label = trim($label, '.-_');

        if ($label === '' || strlen($label) > 32 || !preg_match('/^[a-z0-9][a-z0-9._-]*$/', $label)) {
            throw new \InvalidArgumentException(
                'Token label must be 2–32 chars: lowercase letters, numbers, dot, dash, underscore (e.g. yousef, ali.dev).',
            );
        }

        return $label;
    }

    /**
     * @return list<string> lowercase sha256 hex
     */
    public static function registryHashes(string $root): array
    {
        $dir = self::tokensDir($root);
        if (!is_dir($dir)) {
            return [];
        }

        $hashes = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..' || !str_ends_with(strtolower($name), '.php')) {
                continue;
            }

            $path = $dir . '/' . $name;
            if (!is_file($path)) {
                continue;
            }

            $hash = self::hashFromTokenFile($path);
            if ($hash !== '') {
                $hashes[] = $hash;
            }
        }

        return array_values(array_unique($hashes));
    }

    /**
     * Registry files, then .env PINROLL_GATE_TOKEN, then embedded pingate token_hash.
     *
     * @param array<string, mixed> $gateConfig
     * @return list<string>
     */
    public static function acceptedHashes(string $root, array $gateConfig = []): array
    {
        $hashes = self::registryHashes($root);

        $envToken = self::envPlainToken($root);
        if ($envToken !== '') {
            $hashes[] = TokenGenerator::hashToken($envToken);
        }

        $embedded = strtolower(trim((string) ($gateConfig['token_hash'] ?? '')));
        if ($embedded !== '' && preg_match('/^[a-f0-9]{64}$/', $embedded) === 1) {
            $hashes[] = $embedded;
        }

        return array_values(array_unique($hashes));
    }

    public static function verifyPlainToken(string $plainToken, string $root, array $gateConfig = []): bool
    {
        $plainToken = trim($plainToken);
        if ($plainToken === '' || !preg_match('/^[a-f0-9]{64}$/', $plainToken)) {
            return false;
        }

        foreach (self::acceptedHashes($root, $gateConfig) as $hash) {
            if (TokenGenerator::matchesStoredHash($plainToken, $hash)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Write host token file locally (hash only). Returns absolute path.
     */
    public static function writeTokenFile(string $root, string $label, string $plainToken): string
    {
        $label = self::normalizeLabel($label);
        $dir = self::tokensDir($root);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create tokens directory: ' . $dir);
        }

        $path = $dir . '/' . $label . '.php';
        $content = self::renderTokenFile($label, TokenGenerator::hashToken($plainToken));
        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write token file: ' . $path);
        }

        return $path;
    }

    public static function renderTokenFile(string $label, string $hash): string
    {
        $label = self::normalizeLabel($label);
        $hash = strtolower($hash);
        $created = date('c');

        return <<<PHP
<?php

declare(strict_types=1);

/**
 * PinGate deploy token (hash only). Upload to storage/pinroll/tokens/{$label}.php on the host.
 */
return [
    'label' => '{$label}',
    'hash' => '{$hash}',
    'created' => '{$created}',
];

PHP;
    }

    public static function hashFromTokenFile(string $path): string
    {
        if (!is_file($path)) {
            return '';
        }

        $data = require $path;
        if (!is_array($data)) {
            return '';
        }

        $hash = strtolower(trim((string) ($data['hash'] ?? '')));
        if ($hash === '' || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            return '';
        }

        return $hash;
    }

    public static function envPlainToken(string $root): string
    {
        $fromEnv = $_ENV['PINROLL_GATE_TOKEN'] ?? $_SERVER['PINROLL_GATE_TOKEN'] ?? getenv('PINROLL_GATE_TOKEN');
        if (is_string($fromEnv) && preg_match('/^[a-f0-9]{64}$/', trim($fromEnv))) {
            return trim($fromEnv);
        }

        $file = rtrim(str_replace('\\', '/', $root), '/') . '/.env';
        if (!is_file($file)) {
            return '';
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^PINROLL_GATE_TOKEN\s*=\s*(.*)$/', $line, $matches) !== 1) {
                continue;
            }

            $token = trim(trim((string) $matches[1]), "\"'");
            if (preg_match('/^[a-f0-9]{64}$/', $token)) {
                return $token;
            }
        }

        return '';
    }

    public static function hostUploadPath(string $label): string
    {
        return self::TOKENS_DIR . '/' . self::normalizeLabel($label) . '.php';
    }

    /**
     * @param array<string, mixed> $host Raw host config
     */
    public static function labelFromHost(array $host): string
    {
        $configured = trim((string) ($host['gate']['label'] ?? ''));
        if ($configured !== '') {
            try {
                return self::normalizeLabel($configured);
            } catch (\InvalidArgumentException) {
            }
        }

        return self::defaultLabel();
    }

    public static function defaultLabel(): string
    {
        foreach ([
            getenv('PINROLL_TOKEN_LABEL'),
            getenv('USERNAME'),
            getenv('USER'),
            function_exists('get_current_user') ? get_current_user() : '',
        ] as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            try {
                return self::normalizeLabel($candidate);
            } catch (\InvalidArgumentException) {
            }
        }

        return 'dev';
    }
}
