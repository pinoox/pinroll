<?php

namespace Pinoox\Pinroll\Target;

use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\Config;
use Pinoox\Pinroll\Support\HostDir;
use Pinoox\Pinroll\Support\NativePathResolver;

final class TargetChecker
{
    public function __construct(
        private readonly ?string $projectRoot = null,
    ) {
        $root = $this->projectRoot ?? (defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd());
        Pinroll::configure([], new NativePathResolver((string) $root));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function checkAll(): array
    {
        $results = [];
        foreach (Pinroll::targets()->names() as $name) {
            $results[] = $this->check($name);
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    public function check(string $targetName, ?string $via = null): array
    {
        $target = Pinroll::targets()->resolve($targetName, $via);
        $transport = (string) $target['transport'];

        $result = [
            'target' => $targetName,
            'transport' => $transport,
            'bundle' => (string) ($target['bundle'] ?? ''),
            'ok' => false,
            'message' => '',
            'checks' => [],
        ];

        try {
            $checks = match ($transport) {
                'pinion' => $this->checkPinion($target),
                'ssh' => $this->checkSsh($target),
                'ftp' => $this->checkFtp($target),
                'local' => $this->checkLocal($target),
                default => [['ok' => false, 'label' => 'transport', 'message' => "Unknown transport: {$transport}"]],
            };

            $result['checks'] = $checks;
            $failed = array_filter($checks, static fn (array $c): bool => !($c['ok'] ?? false));

            if ($failed === []) {
                $result['ok'] = true;
                $result['message'] = 'Target is reachable and ready.';
            } else {
                $first = reset($failed);
                $result['message'] = is_array($first) ? (string) ($first['message'] ?? 'Check failed.') : 'Check failed.';
            }
        } catch (\Throwable $e) {
            $result['message'] = $e->getMessage();
            $result['checks'][] = ['ok' => false, 'label' => 'error', 'message' => $e->getMessage()];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $target
     * @return list<array{ok: bool, label: string, message: string}>
     */
    private function checkPinion(array $target): array
    {
        $checks = [];
        $gateUrl = rtrim((string) ($target['gate_url'] ?? ''), '/');
        $token = (string) ($target['token'] ?? '');
        $hostDir = HostDir::fromTarget($target);

        $checks[] = $this->checkField(
            'dir',
            true,
            $hostDir !== '' ? 'subdir: ' . $hostDir : 'site root',
        );

        $checks[] = $this->checkField(
            'gate_entry',
            true,
            HostDir::gateEntryPath($hostDir),
        );

        $checks[] = $this->checkField('gate_url', $gateUrl !== '', $gateUrl !== '' ? $gateUrl : 'Missing gate_url (set in .env)');
        $webDir = HostDir::webPath($hostDir);
        if ($gateUrl !== '' && !str_contains($gateUrl, HostDir::GATE_ENTRY)) {
            $checks[] = $this->checkField(
                'gate_url_path',
                false,
                'gate_url should end with /' . HostDir::gateEntryWebPath($hostDir) . '?route=',
            );
        } elseif ($gateUrl !== '' && $webDir !== '' && !str_contains($gateUrl, '/' . $webDir . '/')) {
            $checks[] = $this->checkField(
                'gate_url_dir',
                false,
                'gate_url should include /' . $webDir . '/ when site is in a subfolder (e.g. https://domain.com/' . $webDir . '/pingate.php?route=)',
            );
        }

        $checks[] = $this->checkField('token', $token !== '', $token !== '' ? 'Token is set' : 'Missing token (run pinroll:init or set .env)');

        if ($gateUrl === '') {
            return $checks;
        }

        $statusUrl = $gateUrl . 'status';
        $http = $this->httpGet($statusUrl, $token);

        $checks[] = $this->checkField(
            'http',
            $http['reachable'],
            $http['reachable']
                ? 'HTTP ' . $http['status'] . ' from PinGate'
                : ($http['error'] ?? 'Could not reach PinGate'),
        );

        if (!$http['reachable']) {
            return $checks;
        }

        if ($token === '') {
            return $checks;
        }

        if ($http['status'] === 401) {
            $checks[] = $this->checkField(
                'pingate',
                false,
                'PinGate is reachable but the Bearer token is invalid. Sync token in .env with pingate.php on the host.',
            );

            return $checks;
        }

        if ($http['status'] >= 500) {
            $checks[] = $this->checkField('pingate', false, 'PinGate server error: ' . ($http['body_excerpt'] ?? 'HTTP ' . $http['status']));

            return $checks;
        }

        $probe = PinGateProbe::validateStatusResponse(
            (int) $http['status'],
            (string) ($http['body'] ?? ''),
            $hostDir,
        );

        $checks[] = $this->checkField('pingate', $probe['ok'], $probe['message']);

        if ($probe['ok'] && $token !== '') {
            $checks[] = $this->checkField('auth', true, 'Bearer token accepted');
        }

        return $checks;
    }

    /**
     * @param array<string, mixed> $target
     * @return list<array{ok: bool, label: string, message: string}>
     */
    private function checkSsh(array $target): array
    {
        $host = (string) ($target['host'] ?? '');
        $user = (string) ($target['user'] ?? '');
        $key = (string) ($target['key'] ?? '');
        $password = (string) ($target['password'] ?? '');
        $hostDir = HostDir::fromTarget($target);

        $checks = [
            $this->checkField('transport', true, 'SSH — PinGate (_pinoox) is not required'),
            $this->checkField(
                'dir',
                true,
                $hostDir !== '' ? 'Deploy root: ' . HostDir::deployRoot($hostDir) . '/' : HostDir::deployRoot($hostDir) . '/ (site root)',
            ),
            $this->checkField(
                'upload_path',
                true,
                'Upload path: ' . HostDir::incomingDir($hostDir) . '/',
            ),
            $this->checkField('host', $host !== '', $host !== '' ? $host : 'Missing host'),
            $this->checkField('user', $user !== '', $user !== '' ? $user : 'Missing user'),
        ];

        if ($host === '' || $user === '') {
            return $checks;
        }

        if (class_exists(\phpseclib3\Net\SSH2::class)) {
            try {
                $ssh = new \phpseclib3\Net\SSH2($host, 22, 10);
                $loggedIn = $key !== ''
                    ? $ssh->login($user, \phpseclib3\Crypt\PublicKeyLoader::load(file_get_contents($key)))
                    : $ssh->login($user, $password);
                $checks[] = $this->checkField('login', (bool) $loggedIn, $loggedIn ? 'SSH login successful' : 'SSH login failed');
            } catch (\Throwable $e) {
                $checks[] = $this->checkField('login', false, 'SSH error: ' . $e->getMessage());
            }

            return $checks;
        }

        $cmd = 'ssh -o BatchMode=yes -o ConnectTimeout=10 -o StrictHostKeyChecking=accept-new '
            . ($key !== '' ? '-i ' . escapeshellarg($key) . ' ' : '')
            . escapeshellarg($user . '@' . $host)
            . ' echo ok 2>&1';
        exec($cmd, $output, $code);
        $checks[] = $this->checkField(
            'login',
            $code === 0,
            $code === 0 ? 'SSH connection successful' : trim(implode("\n", $output) ?: 'SSH connection failed'),
        );

        return $checks;
    }

    /**
     * @param array<string, mixed> $target
     * @return list<array{ok: bool, label: string, message: string}>
     */
    private function checkFtp(array $target): array
    {
        $host = (string) ($target['host'] ?? '');
        $user = (string) ($target['user'] ?? '');
        $password = (string) ($target['password'] ?? '');
        $hostDir = HostDir::fromTarget($target);
        $gateUrl = rtrim((string) ($target['gate_url'] ?? ''), '/');

        $checks = [
            $this->checkField(
                'transport',
                true,
                $gateUrl !== '' ? 'FTP + optional PinGate apply' : 'FTP — PinGate (_pinoox) is not required',
            ),
            $this->checkField(
                'dir',
                true,
                $hostDir !== '' ? 'Deploy root: ' . HostDir::deployRoot($hostDir) . '/' : HostDir::deployRoot($hostDir) . '/ (site root)',
            ),
            $this->checkField(
                'upload_path',
                true,
                'Upload path: ' . HostDir::incomingDir($hostDir) . '/',
            ),
            $this->checkField('host', $host !== '', $host !== '' ? $host : 'Missing host'),
            $this->checkField('user', $user !== '', $user !== '' ? $user : 'Missing user'),
        ];

        if ($host === '' || $user === '' || !function_exists('ftp_connect')) {
            if (!function_exists('ftp_connect')) {
                $checks[] = $this->checkField('ftp', false, 'PHP FTP extension is not available');
            }

            return $checks;
        }

        $connection = @ftp_connect($host, 21, 10);
        if ($connection === false) {
            $checks[] = $this->checkField('connect', false, 'FTP connection failed');

            return $checks;
        }

        $loggedIn = @ftp_login($connection, $user, $password);
        ftp_close($connection);
        $checks[] = $this->checkField('login', $loggedIn, $loggedIn ? 'FTP login successful' : 'FTP login failed');

        if ($gateUrl !== '') {
            $http = $this->httpGet($gateUrl . 'status', (string) ($target['token'] ?? ''));
            $probe = PinGateProbe::validateStatusResponse((int) $http['status'], (string) ($http['body'] ?? ''), $hostDir);
            $checks[] = $this->checkField('pingate', $probe['ok'], $probe['message']);
        }

        return $checks;
    }

    /**
     * @param array<string, mixed> $target
     * @return list<array{ok: bool, label: string, message: string}>
     */
    private function checkLocal(array $target): array
    {
        $config = Pinroll::config();
        $path = (string) ($target['path'] ?? $config->get('incoming_path', 'pinroll/incoming'));

        if ($this->isAbsolutePath($path)) {
            $dest = $path;
        } elseif (str_starts_with($path, 'storage/')) {
            $dest = $config->paths()->root() . '/' . ltrim($path, '/');
        } else {
            $dest = $config->storage(ltrim($path, '/'));
        }

        if (!is_dir($dest) && !@mkdir($dest, 0755, true) && !is_dir($dest)) {
            return [$this->checkField('path', false, 'Cannot create local path: ' . $dest)];
        }

        $writable = is_writable($dest);
        $testFile = $dest . '/.pinroll-check-' . uniqid('', true);
        if ($writable) {
            $writable = @file_put_contents($testFile, 'ok') !== false;
            if (is_file($testFile)) {
                unlink($testFile);
            }
        }

        return [
            $this->checkField('path', true, $dest),
            $this->checkField('writable', $writable, $writable ? 'Directory is writable' : 'Directory is not writable'),
        ];
    }

    /**
     * @return array{reachable: bool, status: int, error: ?string, body: string, body_excerpt: ?string}
     */
    private function httpGet(string $url, string $token): array
    {
        $headers = ['Accept: application/json'];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => 15,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            $error = error_get_last();

            return [
                'reachable' => false,
                'status' => 0,
                'error' => $error['message'] ?? 'Connection failed',
                'body' => '',
                'body_excerpt' => null,
            ];
        }

        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }

        return [
            'reachable' => true,
            'status' => $status,
            'error' => null,
            'body' => $body,
            'body_excerpt' => substr(trim($body), 0, 120),
        ];
    }

    /**
     * @return array{ok: bool, label: string, message: string}
     */
    private function checkField(string $label, bool $ok, string $message): array
    {
        return compact('ok', 'label', 'message');
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
    }
}
