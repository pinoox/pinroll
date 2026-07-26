<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Host\HostGate;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\HostDir;
use Pinoox\Pinroll\Support\PushProgress;
use Pinoox\Pinroll\Target\PinGateClient;
use Pinoox\Pinroll\Transport\FtpUploader;

/**
 * Upload pinroll/vendor.zip via FTP and extract on the host through PinGate.
 */
final class VendorPusher
{
    /**
     * @return array{remote_zip: string, extract: array<string, mixed>}
     */
    public function push(string $hostName, string $localZip): array
    {
        if (!is_file($localZip)) {
            throw new PinrollException('Vendor zip not found: ' . $localZip);
        }

        $raw = Pinroll::hosts()->raw($hostName);
        $resolved = Pinroll::hosts()->resolve($hostName);
        $transport = (string) ($resolved['transport'] ?? 'ftp');

        if ($transport !== 'ftp') {
            throw new PinrollException(
                'pinroll:vendor --push currently supports FTP hosts only (via=ftp). Host transport: ' . $transport,
            );
        }

        $gate = HostGate::credentials($raw);
        $gateUrl = $gate['url'] !== '' ? $gate['url'] : (string) ($resolved['gate_url'] ?? '');
        $token = $gate['token'] !== '' ? $gate['token'] : (string) ($resolved['token'] ?? '');

        if ($gateUrl === '' || $token === '') {
            throw new PinrollException(
                'PinGate URL/token missing. Run php pinoox pinroll:connect / pinroll:gate first.',
            );
        }

        $uploader = new FtpUploader();
        $connection = $uploader->connect(
            (string) ($resolved['host'] ?? ''),
            (string) ($resolved['user'] ?? ''),
            (string) ($resolved['password'] ?? ''),
        );

        $prefix = HostDir::deployRoot(HostDir::fromTarget($resolved));
        $prefix = $prefix === '.' ? '' : rtrim($prefix, '/') . '/';
        $remoteZip = $prefix . 'vendor.zip';

        try {
            PushProgress::arrow('FTP upload ' . basename($localZip) . ' → ' . $remoteZip);
            $uploader->uploadFile($connection, $localZip, $remoteZip);
            PushProgress::arrow('FTP upload done');
        } finally {
            if (is_resource($connection)) {
                ftp_close($connection);
            }
        }

        PushProgress::arrow('PinGate extract vendor.zip');
        $extract = PinGateClient::extractVendor($gateUrl, $token, []);

        return [
            'remote_zip' => $remoteZip,
            'extract' => $extract,
        ];
    }
}
