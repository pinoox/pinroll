<?php

namespace Pinoox\Pinroll\Support;

final class ThemePaths
{
    /**
     * @return list<array{package: string, theme: string, local: string, remote: string}>
     */
    public static function distFolders(string $platformRoot, string $package): array
    {
        $root = rtrim(str_replace('\\', '/', $platformRoot), '/');
        $candidates = [
            [
                'dir' => $root . '/apps/' . $package . '/theme',
                'remote' => 'apps/' . $package . '/theme',
            ],
            [
                'dir' => $root . '/theme',
                'remote' => 'theme',
            ],
        ];

        $folders = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            $themeRoot = $candidate['dir'];
            if (!is_dir($themeRoot)) {
                continue;
            }

            foreach (scandir($themeRoot) ?: [] as $theme) {
                if ($theme === '.' || $theme === '..') {
                    continue;
                }

                $dist = $themeRoot . '/' . $theme . '/dist';
                $key = str_replace('\\', '/', $dist);
                if (!is_dir($dist) || isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $folders[] = [
                    'package' => $package,
                    'theme' => $theme,
                    'local' => $dist,
                    'remote' => $candidate['remote'] . '/' . $theme . '/dist',
                ];
            }
        }

        return $folders;
    }
}
