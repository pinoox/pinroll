<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Support\HostDir;
use Pinoox\Pinroll\Support\PushProgress;
use Pinoox\Pinroll\Host\HostGate;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Target\PinGateClient;
use Pinoox\Pinroll\Target\PinGateProbe;
use Pinoox\Pinroll\Target\PinGateTransport;

/**
 * Probe PinGate health and upload a fresh pingate.php + token file when needed.
 */
final class GateMaintainer
{
    /**
     * Sync pingate.php before push/install when PinGate is configured.
     *
     * @param array<string, mixed> $resolvedTarget
     * @param array<string, mixed> $rawTarget
     */
    public static function ensureBeforeDeploy(
        string $hostName,
        array $resolvedTarget,
        array $rawTarget,
        ?DeployRunner $runner = null,
    ): void {
        $gate = HostGate::credentials($rawTarget);
        $gateUrl = $gate['url'] !== '' ? $gate['url'] : (string) ($resolvedTarget['gate_url'] ?? '');
        $token = $gate['token'] !== '' ? $gate['token'] : (string) ($resolvedTarget['token'] ?? '');

        if ($gateUrl === '' || $token === '') {
            return;
        }

        self::ensureReady($gateUrl, $token, $hostName, $resolvedTarget, $rawTarget, $runner);
    }

    /**
     * @param array<string, mixed> $resolvedTarget
     * @param array<string, mixed> $rawTarget
     */
    public static function ensureReady(
        string $gateUrl,
        string $token,
        string $hostName,
        array $resolvedTarget,
        array $rawTarget,
        ?DeployRunner $runner = null,
    ): void {
        $root = Pinroll::paths()->root();
        $canGate = GateDeployer::canUpload($resolvedTarget);
        $canToken = GateTokenSyncer::canUpload($resolvedTarget, $rawTarget);

        $probe = self::probe($gateUrl, $token, $rawTarget, $hostName);
        if ($probe['ok']) {
            if ($canToken) {
                GateTokenSyncer::sync($root, $hostName, $resolvedTarget, $rawTarget, $token);
            }

            return;
        }

        $authFail = self::isAuthFailure((string) ($probe['message'] ?? ''));
        $repairable = (bool) ($probe['repairable'] ?? false);

        if (($authFail || $repairable) && ($canGate || $canToken)) {
            $runner ??= new DeployRunner($root);

            if ($canGate && ($authFail || $repairable)) {
                PushProgress::arrow('PinGate needs update — uploading pingate.php + token…');
                $runner->initGate($hostName, false, HostDir::fromTarget($rawTarget), $gateUrl, false, true);
            } elseif ($canToken) {
                PushProgress::arrow('Uploading host token file…');
                GateTokenSyncer::sync($root, $hostName, $resolvedTarget, $rawTarget, $token);
            }

            usleep(500_000);

            $probe = self::probe($gateUrl, $token, $rawTarget, $hostName);
            if ($probe['ok']) {
                PushProgress::detail('PinGate ready');

                return;
            }
        }

        throw new PinrollException(self::formatFailure($probe));
    }

    /**
     * @param array<string, mixed> $rawTarget
     * @return array{ok: bool, message?: string, repairable?: bool, hints?: list<string>}
     */
    public static function probe(string $gateUrl, string $token, array $rawTarget, string $hostName): array
    {
        if (!self::requiresAuth($gateUrl)) {
            return [
                'ok' => false,
                'message' => 'PinGate is reachable without authentication — re-upload pingate.php to enforce bearer token.',
                'repairable' => GateDeployer::canUpload($rawTarget) || GateTokenSyncer::canUpload(
                    array_merge($rawTarget, ['transport' => (string) ($rawTarget['via'] ?? 'ftp')]),
                    $rawTarget,
                ),
            ];
        }

        try {
            $data = PinGateClient::status($gateUrl, $token);
            $platform = is_array($data['platform'] ?? null) ? $data['platform'] : null;
            if (is_array($platform) && ($platform['ok'] ?? null) === false) {
                throw new PinrollException((string) ($platform['message'] ?? 'Platform not ready for Pinx install.'));
            }

            return ['ok' => true];
        } catch (PinrollException $e) {
            return self::probeFailure($e->getMessage(), $gateUrl, $token, $rawTarget, $hostName);
        }
    }

    /**
     * @param array<string, mixed> $rawTarget
     * @return array{ok: bool, message?: string, repairable?: bool, hints?: list<string>}
     */
    private static function probeFailure(
        string $message,
        string $gateUrl,
        string $token,
        array $rawTarget,
        string $hostName,
    ): array {
        $repairable = self::isRepairableMessage($message);

        $hostDir = HostDir::fromTarget($rawTarget);
        $webDir = HostDir::webPathFromHost($rawTarget);
        $http = self::httpGetStatus($gateUrl, $token);
        if (!$http['reachable']) {
            return [
                'ok' => false,
                'message' => $message,
                'repairable' => $repairable,
            ];
        }

        $validated = PinGateProbe::validateStatusResponse(
            (int) $http['status'],
            (string) $http['body'],
            $hostDir,
            $webDir,
            $hostName,
        );

        return [
            'ok' => false,
            'message' => (string) ($validated['message'] ?? $message),
            'repairable' => $repairable || self::isRepairableMessage((string) ($validated['message'] ?? '')),
            'hints' => $validated['hints'] ?? [],
        ];
    }

    private static function requiresAuth(string $gateUrl): bool
    {
        $url = GateUrl::route($gateUrl, 'status');
        $anonymous = PinGateTransport::request('GET', $url, ['Accept: application/json'], '', 15);
        if (!$anonymous['reachable']) {
            return true;
        }

        $body = trim($anonymous['body']);
        if ($body === '' || $body[0] !== '{') {
            return true;
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return true;
        }

        if (($json['success'] ?? null) === true) {
            return false;
        }

        $error = strtolower((string) ($json['error'] ?? ''));
        if ($error === '') {
            return true;
        }

        return str_contains($error, 'token')
            || str_contains($error, 'unauthorized')
            || str_contains($error, 'bearer');
    }

    private static function isRepairableMessage(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($message, 'Cannot redeclare pinroll_pingate_run')
            || str_contains($message, 'Not PinGate JSON')
            || str_contains($message, 'Fatal error')
            || str_contains($message, 'Service Unavailable')
            || str_contains($lower, 'http 503')
            || str_contains($message, '503')
            || str_contains($lower, 'missing bearer')
            || str_contains($lower, 'invalid token')
            || str_contains($lower, 'no pingate tokens')
            || str_contains($lower, 'without authentication');
    }

    private static function isAuthFailure(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'invalid token')
            || str_contains($lower, 'missing bearer')
            || str_contains($lower, 'no pingate tokens')
            || str_contains($lower, 'unauthorized');
    }

    /**
     * @param array{ok?: bool, message?: string, hints?: list<string>} $probe
     */
    private static function formatFailure(array $probe): string
    {
        $message = (string) ($probe['message'] ?? 'PinGate is not ready.');
        $hints = $probe['hints'] ?? [];

        return $hints !== [] ? $message . "\n" . implode("\n", $hints) : $message;
    }

    /**
     * @return array{reachable: bool, status: int, body: string}
     */
    private static function httpGetStatus(string $gateUrl, string $token): array
    {
        $headers = ['Accept: application/json'];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $transport = PinGateTransport::request('GET', GateUrl::route($gateUrl, 'status'), $headers, '', 20);
        if (!$transport['reachable']) {
            return ['reachable' => false, 'status' => 0, 'body' => ''];
        }

        return [
            'reachable' => true,
            'status' => $transport['status'],
            'body' => $transport['body'],
        ];
    }
}
