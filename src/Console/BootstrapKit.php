<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\PinGate\GateTokenRegistry;
use Pinoox\Pinroll\Support\HostDir;

/**
 * Human-facing bootstrap package for hosts without FTP/SSH (extract into public_html).
 */
final class BootstrapKit
{
    /**
     * README placed at the root of pinroll-kit-{host}.zip
     */
    public static function readme(string $site, string $deployPath, string $tokenLabel): string
    {
        $site = $site !== '' ? rtrim($site, '/') : 'https://example.com';
        $extract = HostDir::extractGuidePath($deployPath);
        $tokenRel = GateTokenRegistry::hostUploadPath($tokenLabel);
        $statusUrl = $site . '/pingate.php?route=status';

        return <<<TXT
Pinroll bootstrap kit
=====================

Extract this zip INTO your site document root (usually public_html/).
Do not put files inside a nested folder — pingate.php must be public.

After extract you should have:
  {$extract}pingate.php
  {$extract}{$tokenRel}

Next (on your PC):
  1. php pinoox pinroll:check
  2. php pinoox pinroll:deploy

Status URL:
  {$statusUrl}

---
راهنمای فارسی
=============

این zip را داخل ریشه سایت (معمولاً public_html) استخراج کنید.
بعد از استخراج باید این فایل‌ها دیده شوند:

  {$extract}pingate.php
  {$extract}{$tokenRel}

بعد روی سیستم خودتان:

  1. php pinoox pinroll:check
  2. php pinoox pinroll:deploy

آدرس بررسی:
  {$statusUrl}

TXT;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public static function methodChoices(): array
    {
        return [
            ['key' => 'kit', 'label' => 'Zip kit (no FTP) — extract into public_html'],
            ['key' => 'ftp', 'label' => 'FTP — upload PinGate automatically'],
            ['key' => 'ssh', 'label' => 'SSH/SFTP — upload PinGate automatically'],
            ['key' => 'bootstrap-ftp', 'label' => 'FTP once → then Pinion (HTTP uploads)'],
        ];
    }

    /**
     * @return array{via: string, bootstrap_ftp: bool, kit: bool}
     */
    public static function resolveMethod(string $choice): array
    {
        return match (strtolower(trim($choice))) {
            'ftp' => ['via' => 'ftp', 'bootstrap_ftp' => false, 'kit' => false],
            'ssh' => ['via' => 'ssh', 'bootstrap_ftp' => false, 'kit' => false],
            'bootstrap-ftp', 'bootstrap_ftp', 'ftp-once' => [
                'via' => 'ftp',
                'bootstrap_ftp' => true,
                'kit' => false,
            ],
            default => ['via' => 'pinion', 'bootstrap_ftp' => false, 'kit' => true],
        };
    }
}
