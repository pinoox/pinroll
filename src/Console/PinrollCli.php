<?php

namespace Pinoox\Pinroll\Console;

use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\HostDir;
use Symfony\Component\Console\Style\SymfonyStyle;

final class PinrollCli
{
    public static function printInstallResult(SymfonyStyle $io, array $result): void
    {
        $deployId = (string) ($result['deploy_id'] ?? $result['id'] ?? '');
        $installable = (string) ($result['installable'] ?? '');

        $io->newLine();
        $io->block('Install completed', 'OK', 'fg=black;bg=green', ' ', true);

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
     * @param array<string, mixed> $result
     * @deprecated Use printInstallResult()
     */
    public static function printApplyResult(SymfonyStyle $io, array $result): void
    {
        self::printInstallResult($io, $result);
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
        $target = (string) ($data['host'] ?? $data['target'] ?? 'production');
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
        $io->writeln('  <fg=gray>Next:</> <comment>php pinoox pinroll:deploy' . self::hostCliSuffix($target) . '</comment>');
        $io->writeln('  <fg=gray>or push only:</> <comment>php pinoox pinroll:push' . self::hostCliSuffix($target) . '</comment>');
    }

    /**
     * @param array{
     *     config?: string,
     *     target?: string,
     *     env_keys?: list<string>,
     *     env_created?: list<string>
     * } $data
     */
    public static function printInitSummary(SymfonyStyle $io, array $data): void
    {
        $target = (string) ($data['host'] ?? $data['target'] ?? 'production');

        $io->success('Pinroll initialized');

        if (!empty($data['config'])) {
            $io->writeln('  <fg=green>config</>  ' . self::relPath($data['config']));
        }

        $io->newLine();
        $io->section('Next steps');
        $io->writeln([
            '  <fg=yellow>1.</> Set FTP credentials in <comment>.env</comment>:',
            '       PINROLL_' . strtoupper(preg_replace('/[^a-zA-Z0-9]+/', '_', $target) ?: 'PRODUCTION') . '_HOST=',
            '       PINROLL_' . strtoupper(preg_replace('/[^a-zA-Z0-9]+/', '_', $target) ?: 'PRODUCTION') . '_USER=',
            '       PINROLL_' . strtoupper(preg_replace('/[^a-zA-Z0-9]+/', '_', $target) ?: 'PRODUCTION') . '_PASSWORD=',
            '',
            '  <fg=yellow>2.</> Connect & upload PinGate:',
            '       <comment>php pinoox pinroll:connect</comment>',
            '',
            '  <fg=yellow>3.</> Go live:',
            '       <comment>php pinoox pinroll:deploy' . self::hostCliSuffix($target) . '</comment>',
            '       <fg=gray>or upload only:</> <comment>php pinoox pinroll:push' . self::hostCliSuffix($target) . '</comment>',
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function printConnectStatus(SymfonyStyle $io, array $data): void
    {
        $check = is_array($data['check'] ?? null) ? $data['check'] : [];
        $ok = (bool) ($check['ok'] ?? false);
        $hostName = (string) ($data['host'] ?? $data['target'] ?? '');

        $io->newLine();
        $io->block(
            $ok ? 'Host connected' : 'Connection check failed',
            $ok ? 'OK' : 'FAIL',
            $ok ? 'fg=black;bg=green' : 'fg=white;bg=red',
            ' ',
            true,
        );

        foreach ($check['checks'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $label = (string) ($item['label'] ?? 'check');
            $message = (string) ($item['message'] ?? '');
            $itemOk = (bool) ($item['ok'] ?? false);
            $icon = $itemOk ? '<fg=green;options=bold>✓</>' : '<fg=red;options=bold>✗</>';

            $io->writeln('  ' . $icon . ' <info>' . self::escape($label) . '</>'
                . ($message !== '' ? '  ' . self::escape($message) : ''));
        }

        if (($check['message'] ?? '') !== '' && ($check['checks'] ?? []) === []) {
            $io->writeln('  ' . self::escape((string) $check['message']));
        }

        $hostArg = self::hostCliSuffix($hostName);
        $io->newLine();

        if ($ok) {
            $io->writeln('  Go live (push + install):');
            $io->writeln('  <comment>php pinoox pinroll:deploy' . $hostArg . '</comment>');
            $io->writeln(
                '  <fg=gray>or</> <comment>php pinoox pinroll:push' . $hostArg . '</comment>'
                . ' then <comment>php pinoox pinroll:install' . $hostArg . '</comment>',
            );
        } else {
            $io->writeln('  <fg=gray>Fix credentials in .env or run</> <comment>php pinoox pinroll:connect --reset</comment>');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function printConnectResult(SymfonyStyle $io, array $data): void
    {
        $target = (string) ($data['host'] ?? $data['target'] ?? 'production');
        $uploaded = (bool) ($data['uploaded'] ?? false);
        $gateUrl = (string) ($data['gate_url'] ?? '');

        $io->newLine();
        $io->block(
            $uploaded ? 'Setup complete — PinGate uploaded' : 'Setup complete — PinGate ready',
            'OK',
            'fg=black;bg=green',
            ' ',
            true,
        );

        if ($gateUrl !== '') {
            $io->writeln('  <fg=gray>URL</>  <comment>' . self::escape($gateUrl) . '</comment>');
        }

        if ($uploaded) {
            $io->writeln('  <fg=green>Uploaded</> pingate.php + gate/ via FTP');
        }

        $hostArg = self::hostCliSuffix($target);

        $io->newLine();
        $io->writeln('  Go live (push + install):');
        $io->writeln('  <comment>php pinoox pinroll:deploy' . $hostArg . '</comment>');
        $io->writeln(
            '  <fg=gray>or</> <comment>php pinoox pinroll:push' . $hostArg . '</comment>'
            . ' then <comment>php pinoox pinroll:install' . $hostArg . '</comment>',
        );
    }

    /**
     * @param array<string, mixed> $result
     */
    public static function printPushResult(SymfonyStyle $io, array $result): void
    {
        $deployId = (string) ($result['deploy_id'] ?? $result['id'] ?? '');

        $io->newLine();
        $io->block(
            'Push complete',
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

        if ($deployId !== '' && self::hasUploadStep($result) && !self::hasInstallStep($result)) {
            $hostName = self::resolveHostName($result);
            $hostArg = self::hostCliSuffix($hostName);
            $deployPath = self::deployPathHint($hostName);

            $io->newLine();
            $io->section('Next: install on host');
            $io->writeln([
                '<fg=gray>Go live (push + install):</>',
                '  <fg=yellow>php pinoox pinroll:deploy' . $hostArg . '</>',
                '',
                '<fg=gray>Or install the upload you just pushed:</>',
                '  <fg=yellow>php pinoox pinroll:install' . $hostArg . '</>',
                '',
                '<fg=gray>SSH shell on host:</>',
                '  <fg=yellow>cd</> <comment>' . self::escape($deployPath) . '</comment>  <fg=gray>(site root)</>',
                '  <fg=yellow>php pinoox pinroll:install --local</>',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function hasInstallStep(array $result): bool
    {
        foreach ($result['steps'] ?? [] as $step) {
            if (!is_array($step)) {
                continue;
            }

            $name = (string) ($step['step'] ?? $step['name'] ?? '');
            $status = (string) ($step['status'] ?? '');

            if (($name === 'install' || $name === 'apply') && $status === 'ok') {
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

    /**
     * @param array<string, mixed> $result
     */
    private static function resolveHostName(array $result): string
    {
        return (string) ($result['host'] ?? $result['target'] ?? '');
    }

    private static function hostCliSuffix(string $hostName): string
    {
        if ($hostName === '') {
            return '';
        }

        try {
            $default = (string) (Pinroll::config()->get('default_host', '') ?? '');
            if ($default !== '' && $hostName === $default) {
                return '';
            }
        } catch (\Throwable) {
            // Pinroll not booted — include host name in examples.
        }

        return ' ' . $hostName;
    }

    private static function deployPathHint(string $hostName): string
    {
        if ($hostName === '') {
            return '~/public_html';
        }

        try {
            $raw = Pinroll::hosts()->raw($hostName);
            $path = (string) ($raw['deploy_path'] ?? '');
            if ($path !== '') {
                return $path;
            }
        } catch (\Throwable) {
        }

        return '~/public_html';
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
