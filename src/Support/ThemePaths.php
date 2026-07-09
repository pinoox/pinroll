<?php

namespace Pinoox\Pinroll\Support;

final class ThemePaths
{
    /**
     * @return list<array{package: string, theme: string, local: string, remote: string}>
     */
    public static function distFolders(string $platformRoot, string $package): array
    {
        $themeRoot = rtrim($platformRoot, '/') . '/apps/' . $package . '/theme';
        if (!is_dir($themeRoot)) {
            return [];
        }

        $folders = [];
        foreach (scandir($themeRoot) ?: [] as $theme) {
            if ($theme === '.' || $theme === '..') {
                continue;
            }

            $dist = $themeRoot . '/' . $theme . '/dist';
            if (!is_dir($dist)) {
                continue;
            }

            $folders[] = [
                'package' => $package,
                'theme' => $theme,
                'local' => $dist,
                'remote' => 'apps/' . $package . '/theme/' . $theme . '/dist',
            ];
        }

        return $folders;
    }
}
