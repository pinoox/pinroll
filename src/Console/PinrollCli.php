<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Support\HostDir;
use Symfony\Component\Console\Style\SymfonyStyle;

final class PinrollCli
{
    /**
     * @param array<string, mixed> $result
     */
    public static function printApplyResult(SymfonyStyle $io, array $result): void
    {
        $deployId = (string) ($result['deploy_id'] ?? $result['id'] ?? '');
        $installable = (string) ($result['installable'] ?? '');

        $io->newLine();
        $io->block('Apply completed', 'OK', 'fg=black;bg=green', ' ', true);

        if ($deployId !== '') {
            $io->writeln('  <fg=gray>Deploy</>  <info>' . self::escape($deployId) . '</info>');
        }

        if ($installable !== '') {
            $io->writeln('  <fg=gray>Package</> <comment>' . self::escape(self::relPath($installable)) . '</comment>');
        }

        foreach ($result['steps'] ?? [] as $step) {
            if (!is_array($step)) {
                continue;
            }

            $label = (string) ($step['step'] ?? $step['name'] ?? 'step');
            $state = (string) ($step['status'] ?? 'ok');
            $message = (string) ($step['message'] ?? '');

            if ($state === 'skipped') {
                continue;
            }

            if ($label !== 'apply' && !str_starts_with($label, 'install')) {
                continue;
            }

            if ($state === 'failed') {
                $io->writeln('  <fg=red;options=bold>✗</> <fg=red>' . self::escape($label) . '</>'
                    . ($message !== '' ? ' <fg=gray>—</> ' . self::escape($message) : ''));

                continue;
            }

            $io->writeln('  <fg=green;options=bold>✓</> <info>' . self::escape($label) . '</>'
                . ($message !== '' ? ' <fg=gray>—</> <comment>' . self::escape($message) . '</>' : ''));
        }
    }

    /**
     * @param array{
     *     target?: string,
     *     zip?: string|null,
     *     entry?: string|null,
     *     gate_dir?: string|null,
     *     extract_to?: string,
     *     gate_url?: string,
     *     token?: string,
     *     token_key?: string,
     *     url_key?: string,
     *     uploaded?: bool,
     *     upload?: array{remote_root?: string, files?: int}|null
     * } $data
     */
    public static function printGateInitResult(SymfonyStyle $io, array $data): void
    {
        $target = (string) ($data['target'] ?? 'production');
        $token = (string) ($data['token'] ?? '');
        $gateUrl = (string) ($data['gate_url'] ?? '');
        $isExample = (bool) ($data['gate_url_is_example'] ?? ($gateUrl === ''));
        $dir = (string) ($data['dir'] ?? '');
        $zip = (string) ($data['zip'] ?? '');
        $extractTo = (string) ($data['extract_to'] ?? '');
        $uploaded = (bool) ($data['uploaded'] ?? false);
        $urlKey = (string) ($data['url_key'] ?? 'PINROLL_' . strtoupper($target) . '_URL');
        $tokenKey = (string) ($data['token_key'] ?? 'PINROLL_' . strtoupper($target) . '_TOKEN');

        $displayUrl = $isExample
            ? \Pinoox\Pinroll\Target\TargetGate::exampleUrl($dir !== '' ? $dir : null)
            : $gateUrl;

        $io->newLine();
        $io->block($uploaded ? 'PinGate uploaded' : 'PinGate ready', 'OK', 'fg=black;bg=green', ' ', true);

        if ($token !== '') {
            $reused = (bool) ($data['token_reused'] ?? false);
            $io->section($reused ? 'Token (reused from .env — hash matches host)' : 'Token (written to .env)');
            $io->writeln('  <info>' . self::escape($token) . '</info>');
            if ($reused) {
                $io->writeln('  <fg=gray>Rotate:</> <comment>php pinoox pinroll:gate ' . $target . ' --rotate</comment>');
            } else {
                $io->writeln('  <fg=gray>Already saved to .env as</> <comment>' . self::escape($tokenKey) . '</comment>');
            }
        }

        if ($displayUrl !== '') {
            $io->section($isExample ? 'PinGate URL (example — use your real domain)' : 'PinGate URL');
            $io->writeln('  <comment>' . self::escape($displayUrl) . '</comment>');
        }

        $io->section('.env');
        $io->writeln([
            '  <fg=yellow>' . self::escape($urlKey) . '</>=' . self::escape($displayUrl !== '' ? $displayUrl : \Pinoox\Pinroll\Target\TargetGate::exampleUrl()),
            '  <fg=yellow>' . self::escape($tokenKey) . '</>=' . ($token !== '' ? self::escape($token) : '<token>'),
        ]);

        $io->section('pinroll.config.php — top-level gate');
        $io->writeln([
            "  <fg=gray>'gate' => [</>",
            "      'url' => env('{$urlKey}', ''),",
            "      'token' => env('{$tokenKey}', ''),",
            '  ],',
        ]);

        if ($uploaded) {
            $remote = is_array($data['upload'] ?? null) ? (string) ($data['upload']['remote_root'] ?? '') : '';
            $files = is_array($data['upload'] ?? null) ? (int) ($data['upload']['files'] ?? 0) : 0;
            $io->section('FTP');
            $io->writeln([
                '  <fg=green>Uploaded</> pingate.php + gate/' . ($remote !== '' ? ' → <comment>' . self::escape($remote) . '</comment>' : ''),
                $files > 0 ? '  <fg=gray>Files:</> ' . $files : '',
                '  <fg=gray>Local PinGate files removed</>',
            ]);
        } elseif ($zip !== '') {
            $io->section('Upload to server');
            $io->writeln([
                '  <fg=gray>Zip</>      <comment>' . self::escape(self::relPath($zip)) . '</comment>',
                '  <fg=gray>Extract</> <comment>' . self::escape($extractTo) . '</comment>',
                '  <fg=gray>Files</>   pingate.php + gate/ next to platform vendor/',
            ]);
        } elseif (!empty($data['entry']) || !empty($data['gate_dir'])) {
            $io->section('Local files (no FTP upload)');
            $io->writeln([
                '  <fg=gray>Entry</> <comment>pinroll/pingate.php</comment>',
                '  <fg=gray>Gate</>  <comment>pinroll/gate/</comment>',
                '  <fg=gray>Copy to</> <comment>' . self::escape($extractTo) . '</comment>',
                '',
                '  Or: <comment>php pinoox pinroll:gate ' . $target . ' -z</comment> for a zip',
            ]);
        }

        $io->newLine();
        $io->writeln('  <fg=gray>Next:</> <comment>php pinoox pinroll:push ' . $target . ' -a</comment>');
    }

    /**
     * @param array{config?: string, target?: string} $data
     */
    public static function printInitSummary(SymfonyStyle $io, array $data): void
    {
        $target = (string) ($data['target'] ?? 'production');

        if (!empty($data['ready_for_push'])) {
            $io->success('Pinroll ready for push + apply');
            $io->writeln('  <comment>php pinoox pinroll:push ' . $target . ' -a</comment>');

            return;
        }

        $io->success('Pinroll ready');

        if (!empty($data['config'])) {
            $io->writeln('  <fg=green>config</>  ' . self::relPath($data['config']));
        }

        $io->newLine();

        if (!empty($data['gate_configured'])) {
            $io->writeln('  <fg=gray>PinGate configured — finish upload if needed, then:</>');
            $io->writeln('  <fg=gray>1.</> pinroll:check ' . $target);
            $io->writeln('  <fg=gray>2.</> pinroll:push ' . $target . ' -a');
        } else {
            $io->writeln('  <fg=gray>First-time host setup:</>');
            $io->writeln('  <fg=gray>1.</> pinroll:vendor  <fg=gray>→ export vendor.zip (core + deps; replace on host when updating)</>');
            $io->writeln('  <fg=gray>2.</> pinroll:gate ' . $target . '  <fg=gray>→ FTP upload PinGate (or -z for zip)</>');
            $io->writeln('  <fg=gray>3.</> pinroll:check ' . $target);
            $io->writeln('  <fg=gray>4.</> pinroll:push ' . $target . ' -a');
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    public static function printPushResult(SymfonyStyle $io, array $result): void
    {
        $status = (string) ($result['status'] ?? 'ok');
        $deployId = (string) ($result['deploy_id'] ?? $result['id'] ?? '');

        $io->newLine();
        $io->block(
            'Push finished — ' . $status,
            'OK',
            'fg=black;bg=green',
            ' ',
            true,
        );

        foreach ($result['steps'] ?? [] as $step) {
            if (!is_array($step)) {
                continue;
            }

            $label = (string) ($step['step'] ?? $step['name'] ?? 'step');
            $state = (string) ($step['status'] ?? 'ok');
            $message = (string) ($step['message'] ?? '');

            if ($state === 'skipped') {
                continue;
            }

            if ($state === 'failed') {
                $io->writeln('  <fg=red;options=bold>✗</> <fg=red>' . self::escape($label) . '</>'
                    . ($message !== '' ? ' <fg=gray>—</> ' . self::escape($message) : ''));

                continue;
            }

            $io->writeln('  <fg=green;options=bold>✓</> <info>' . self::escape($label) . '</>'
                . ($message !== '' ? ' <fg=gray>—</> <comment>' . self::escape($message) . '</>' : ''));
        }

        if ($deployId !== '' && self::hasUploadStep($result) && !self::hasApplyStep($result)) {
            $io->newLine();
            $io->section('Install on server');
            $io->writeln([
                '<fg=gray>Option A — PinGate (recommended for FTP):</>',
                '  <fg=yellow>php pinoox pinroll:gate</> <fg=gray>→ FTP upload PinGate, add top-level gate { url, token }</>',
                '  <fg=yellow>php pinoox pinroll:push production -a</>',
                '',
                '<fg=gray>Option B — apply from laptop (PinGate):</>',
                '  <fg=yellow>php pinoox pinroll:apply production</>',
                '',
                '<fg=gray>Option C — SSH shell on host:</>',
                '  <fg=yellow>cd</> <comment>~/public_html</comment>  <fg=gray>(site root)</>',
                '  <fg=yellow>php pinoox pinroll:apply --local</>',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function hasApplyStep(array $result): bool
    {
        foreach ($result['steps'] ?? [] as $step) {
            if (!is_array($step)) {
                continue;
            }

            $name = (string) ($step['step'] ?? $step['name'] ?? '');
            $status = (string) ($step['status'] ?? '');

            if ($name === 'apply' && $status === 'ok') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function hasUploadStep(array $result): bool
    {
        foreach ($result['steps'] ?? [] as $step) {
            if (!is_array($step)) {
                continue;
            }

            $name = (string) ($step['step'] ?? $step['name'] ?? '');
            $status = (string) ($step['status'] ?? '');

            if ($name === 'transport' && $status === 'ok') {
                return true;
            }
        }

        return false;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $result
     */
    public static function printCheckResult(SymfonyStyle $io, array $result): void
    {
        $name = (string) ($result['target'] ?? 'unknown');
        $ok = (bool) ($result['ok'] ?? false);

        if ($ok) {
            $io->writeln('<fg=green>✓</> <info>' . $name . '</info>  ' . (string) ($result['message'] ?? 'OK'));

            return;
        }

        $io->writeln('<fg=red>✗</> <info>' . $name . '</info>  ' . (string) ($result['message'] ?? 'Failed'));

        foreach ($result['checks'] ?? [] as $check) {
            if (!is_array($check) || ($check['ok'] ?? false)) {
                continue;
            }

            $io->writeln('    <fg=red>·</> ' . (string) ($check['message'] ?? $check['label'] ?? 'check failed'));
        }
    }

    /**
     * @return array{
     *     extract_to: string,
     *     gate_url: string,
     *     zip_rel: string
     * }
     */
    public static function deployMeta(?string $hostDir, string $zipPath, string $gateUrl = ''): array
    {
        $hostDir = HostDir::normalize($hostDir);
        $extract = HostDir::deployRoot($hostDir) . '/';

        return [
            'extract_to' => $extract,
            'gate_url' => $gateUrl !== '' ? $gateUrl : HostDir::gateUrlFromDomain('pinoox.com', $hostDir),
            'zip_rel' => self::relPath($zipPath),
        ];
    }

    public static function relPath(string $path): string
    {
        if (preg_match('#/pinroll/.+#', $path, $match)) {
            return ltrim($match[0], '/');
        }

        if (defined('PINOOX_BASE_PATH')) {
            $root = rtrim((string) PINOOX_BASE_PATH, '/') . '/';
            if (str_starts_with($path, $root)) {
                return substr($path, strlen($root));
            }
        }

        return $path;
    }
}
