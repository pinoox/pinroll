<?php

namespace Pinoox\Pinroll\Console;

/**
 * Transport-aware PinGate upload (FTP or SSH).
 */
final class GateDeployer
{
    /**
     * @param array<string, mixed> $resolvedHost
     * @return array{remote_root: string, files: int}
     */
    public function upload(array $resolvedHost, string $localEntry, string $localGateDir): array
    {
        $transport = (string) ($resolvedHost['transport'] ?? 'ftp');

        return match ($transport) {
            'ssh' => (new GateSshDeployer())->upload($resolvedHost, $localEntry, $localGateDir),
            default => (new GateFtpDeployer())->upload($resolvedHost, $localEntry, $localGateDir),
        };
    }

    /**
     * @param array<string, mixed> $resolvedHost
     */
    public static function canUpload(array $resolvedHost): bool
    {
        return GateFtpDeployer::canUpload($resolvedHost) || GateSshDeployer::canUpload($resolvedHost);
    }
}
