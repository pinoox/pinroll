<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Rollout\RolloutSession;
use Pinoox\Pinroll\Support\HostDir;
use Pinoox\Pinroll\Support\PushProgress;
use Pinoox\Pinroll\Host\HostGate;
use Pinoox\Pinroll\Host\RetentionPolicy;
use Pinoox\Pinroll\Target\PinGateClient;

final class ReleaseApplier
{
    /**
     * @param array<string, mixed> $resolvedTarget
     * @param array<string, mixed> $rawTarget
     */
    public function applyOnTarget(
        array $resolvedTarget,
        array $rawTarget,
        string $deployId,
        RolloutSession $session,
    ): void {
        $transport = (string) ($resolvedTarget['transport'] ?? '');

        if ($transport === 'local') {
            $this->applyLocal($deployId, $session);

            return;
        }

        $gate = HostGate::credentials($rawTarget);
        $gateUrl = $gate['url'] !== '' ? $gate['url'] : (string) ($resolvedTarget['gate_url'] ?? '');
        $token = $gate['token'] !== '' ? $gate['token'] : (string) ($resolvedTarget['token'] ?? '');

        if ($gateUrl !== '' && $token !== '') {
            $this->applyViaPinGate($gateUrl, $token, $deployId, $session, $resolvedTarget, $rawTarget);

            return;
        }

        $ssh = $this->sshCredentials($rawTarget, $resolvedTarget);
        if ($ssh !== null) {
            $this->applyViaSsh($ssh, $rawTarget, $deployId, $session);

            return;
        }

        throw new PinrollException(
            implode("\n", HostGate::setupGuide((string) ($resolvedTarget['name'] ?? 'production'))),
        );
    }

    private function applyLocal(string $deployId, RolloutSession $session): void
    {
        PushProgress::detail('Running local apply ' . $deployId);
        $result = (new DeployRunner())->apply($deployId !== '' ? $deployId : null);

        foreach ($result['steps'] ?? [] as $step) {
            if (!is_array($step)) {
                continue;
            }

            $name = (string) ($step['step'] ?? '');
            $status = (string) ($step['status'] ?? '');
            $message = (string) ($step['message'] ?? '');

            if (!str_starts_with($name, 'install')) {
                continue;
            }

            $session->addStep($name, $status, $message);
        }

        if (($result['status'] ?? '') === 'failed') {
            throw new PinrollException((string) ($result['error'] ?? 'Apply failed on host.'));
        }

        $session->addStep('apply', 'ok', 'Applied on host: ' . $deployId);
    }

    private function applyViaPinGate(
        string $gateUrl,
        string $token,
        string $deployId,
        RolloutSession $session,
        array $resolvedTarget,
        array $rawTarget,
    ): void {
        $host = array_merge($rawTarget, $resolvedTarget);
        $retention = RetentionPolicy::settings($host);
        $label = $deployId !== '' ? $deployId : 'latest';
        PushProgress::arrow('PinGate install: ' . $label);
        $result = PinGateClient::install($gateUrl, $token, $deployId, [
            'keep' => $retention['keep'],
            'store' => $retention['store'],
            'auto_clean' => $retention['auto_clean'],
        ]);
        $status = (string) ($result['status'] ?? 'installed');
        $appliedId = (string) ($result['deploy_id'] ?? $deployId);
        $session->addStep(
            'install',
            'ok',
            'Installed via PinGate (' . $status . ')' . ($appliedId !== '' ? ': ' . $appliedId : ''),
        );

        $cleanup = RetentionPolicy::cleanAfterInstall($host, [
            'gate_url' => $gateUrl,
            'token' => $token,
        ]);

        self::reportCleanup($session, $cleanup, $retention);
    }

    /**
     * @param array<string, mixed>|null $cleanup
     * @param array{keep: int, store: string, auto_clean: bool} $retention
     */
    private static function reportCleanup(RolloutSession $session, ?array $cleanup, array $retention): void
    {
        if (!$retention['auto_clean']) {
            return;
        }

        $parts = [];

        if (is_array($cleanup['local'] ?? null)) {
            $deleted = (int) ($cleanup['local']['files_deleted'] ?? 0);
            $parts[] = 'local ' . $deleted . ' removed';
        }

        if (($retention['store'] === 'remote' || $retention['store'] === 'both')) {
            if (isset($cleanup['remote_skipped'])) {
                $parts[] = 'remote skipped (no token)';
            } elseif (isset($cleanup['remote_error'])) {
                $parts[] = 'remote error: ' . (string) $cleanup['remote_error'];
            } elseif (is_array($cleanup['remote'] ?? null)) {
                $parts[] = 'remote ' . (int) ($cleanup['remote']['files_deleted'] ?? 0) . ' removed';
            }
        }

        if ($parts === []) {
            return;
        }

        $message = 'Retention keep=' . $retention['keep'] . ' store=' . $retention['store']
            . ' — ' . implode(', ', $parts);
        $session->addStep('cleanup', 'ok', $message);
        PushProgress::detail($message);
    }

    /**
     * @param array{host: string, user: string, password: string, key: string} $ssh
     * @param array<string, mixed> $rawTarget
     */
    private function applyViaSsh(array $ssh, array $rawTarget, string $deployId, RolloutSession $session): void
    {
        $deployRoot = HostDir::deployRoot(HostDir::fromTarget($rawTarget));
        $cd = $deployRoot === '.' ? '.' : $deployRoot;
        $remote = 'cd ' . escapeshellarg($cd) . ' && php pinoox pinroll:install --local'
            . ($deployId !== '' ? ' ' . escapeshellarg($deployId) : '');

        PushProgress::detail('SSH apply on ' . $ssh['host']);

        if (class_exists(\phpseclib3\Net\SSH2::class)) {
            $this->applyViaPhpseclibSsh($ssh, $remote, $session);

            return;
        }

        $cmd = 'ssh -o BatchMode=yes -o ConnectTimeout=30 -o StrictHostKeyChecking=accept-new '
            . ($ssh['key'] !== '' && is_file($ssh['key']) ? '-i ' . escapeshellarg($ssh['key']) . ' ' : '')
            . escapeshellarg($ssh['user'] . '@' . $ssh['host'])
            . ' ' . escapeshellarg($remote)
            . ' 2>&1';

        exec($cmd, $output, $code);

        if ($code !== 0) {
            $message = trim(implode("\n", $output));
            $session->addStep('apply', 'failed', $message !== '' ? $message : 'SSH apply failed');

            throw new PinrollException($message !== '' ? $message : 'SSH apply failed.');
        }

        $session->addStep('apply', 'ok', 'Applied on host via SSH (' . $deployId . ')');
    }

    /**
     * @param array{host: string, user: string, password: string, key: string} $ssh
     */
    private function applyViaPhpseclibSsh(array $ssh, string $remote, RolloutSession $session): void
    {
        $sshClient = new \phpseclib3\Net\SSH2($ssh['host'], 22, 30);
        $loggedIn = $ssh['key'] !== '' && is_file($ssh['key'])
            ? $sshClient->login($ssh['user'], \phpseclib3\Crypt\PublicKeyLoader::load((string) file_get_contents($ssh['key'])))
            : $sshClient->login($ssh['user'], $ssh['password']);

        if (!$loggedIn) {
            $session->addStep('apply', 'failed', 'SSH login failed');

            throw new PinrollException('SSH login failed for apply.');
        }

        $output = trim((string) $sshClient->exec($remote));
        $code = (int) $sshClient->getExitStatus();

        if ($code !== 0) {
            $session->addStep('apply', 'failed', $output !== '' ? $output : 'SSH apply failed');

            throw new PinrollException($output !== '' ? $output : 'SSH apply failed.');
        }

        $session->addStep('apply', 'ok', 'Applied on host via SSH');
        if ($output !== '') {
            PushProgress::detail($output);
        }
    }

    /**
     * @param array<string, mixed> $rawTarget
     * @param array<string, mixed> $resolvedTarget
     * @return array{host: string, user: string, password: string, key: string}|null
     */
    private function sshCredentials(array $rawTarget, array $resolvedTarget): ?array
    {
        if (is_array($rawTarget['ssh'] ?? null)) {
            $block = $rawTarget['ssh'];
            $host = (string) ($block['host'] ?? '');
            $user = (string) ($block['user'] ?? '');

            if ($host !== '' && $user !== '') {
                return [
                    'host' => $host,
                    'user' => $user,
                    'password' => (string) ($block['password'] ?? ''),
                    'key' => (string) ($block['key'] ?? ''),
                ];
            }
        }

        if ((string) ($resolvedTarget['transport'] ?? '') === 'ssh') {
            $host = (string) ($resolvedTarget['host'] ?? '');
            $user = (string) ($resolvedTarget['user'] ?? '');

            if ($host !== '' && $user !== '') {
                return [
                    'host' => $host,
                    'user' => $user,
                    'password' => (string) ($resolvedTarget['password'] ?? ''),
                    'key' => (string) ($resolvedTarget['key'] ?? ''),
                ];
            }
        }

        return null;
    }
}
