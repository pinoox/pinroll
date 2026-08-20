<?php

namespace Pinoox\Pinroll\Bridge;

/**
 * Detect app/theme .pinx archives vs platform zips (works with older pincore too).
 */
final class PinxPackageDetect
{
    public static function isPinxPackage(string $archivePath): bool
    {
        if (!is_file($archivePath)) {
            return false;
        }

        if (
            class_exists(\Pinoox\Component\Package\Pinx\PlatformArchive::class)
            && method_exists(\Pinoox\Component\Package\Pinx\PlatformArchive::class, 'isPinxPackageArchive')
        ) {
            return \Pinoox\Component\Package\Pinx\PlatformArchive::isPinxPackageArchive($archivePath);
        }

        return self::detectFromZip($archivePath);
    }

    public static function isPlatformArchive(string $archivePath): bool
    {
        if (!is_file($archivePath)) {
            return false;
        }

        if (self::isPinxPackage($archivePath)) {
            return false;
        }

        if (
            class_exists(\Pinoox\Component\Package\Pinx\PlatformArchive::class)
            && method_exists(\Pinoox\Component\Package\Pinx\PlatformArchive::class, 'isPlatformArchive')
        ) {
            return \Pinoox\Component\Package\Pinx\PlatformArchive::isPlatformArchive($archivePath);
        }

        return false;
    }

    public static function detectFromZip(string $archivePath): bool
    {
        $lower = strtolower($archivePath);

        if (!class_exists(\ZipArchive::class)) {
            return str_ends_with($lower, '.pinx') || str_ends_with($lower, '.pin');
        }

        $zip = new \ZipArchive();
        if ($zip->open($archivePath) !== true) {
            return str_ends_with($lower, '.pinx') || str_ends_with($lower, '.pin');
        }

        try {
            $raw = $zip->getFromName('manifest.json');
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && ($decoded['format'] ?? null) === 'pinx') {
                    return true;
                }
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (!is_string($name)) {
                    continue;
                }

                $normalized = ltrim(str_replace('\\', '/', $name), '/');
                if (str_starts_with($normalized, 'payload/')) {
                    return true;
                }
            }

            return str_ends_with($lower, '.pinx') || str_ends_with($lower, '.pin');
        } finally {
            $zip->close();
        }
    }
}
