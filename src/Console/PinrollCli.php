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
     * @param array<string, mixed> $data
     */
    public static function printProvisionResult(SymfonyStyle $io, array $data): void
    {
        $host = (string) ($data['host'] ?? '');
        $io->newLine();
        $io->block('Host provisioned', 'OK', 'fg=black;bg=green', ' ', true);

        if (!empty($data['setup_only'])) {
            $io->writeln('  <fg=gray>Mode</>  setup-only (zip extract skipped)');
        }

        if (is_array($data['bootstrap'] ?? null)) {
            $io->writeln('  <fg=green;options=bold>✓</> <info>platform.zip extracted</info>');
        }

        if (is_array($data['check_db'] ?? null) && !empty($data['check_db']['ok'])) {
            $io->writeln('  <fg=green;options=bold>✓</> <info>database reachable from host</info>');
        }

        if (is_array($data['setup'] ?? null) && !empty($data['setup']['installed'])) {
            $io->writeln('  <fg=green;options=bold>✓</> <info>installer setup finished</info>');
        }

        $hostArg = self::hostCliSuffix($host);
        $io->newLine();
        $io->writeln('  Later updates (not a fresh install):');
        $io->writeln('  <comment>php pinoox pinroll:deploy --full' . $hostArg . '</comment>');
        $io->writeln('  <fg=gray>platform + every installed app</>');
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
        $embedToken = (bool) ($data['embed_token'] ?? false);

        $displayUrl = $isExample
            ? \Pinoox\Pinroll\Target\TargetGate::exampleUrl($dir !== '' ? $dir : null)
            : $gateUrl;

        $io->newLine();
        $io->block($uploaded ? 'PinGate uploaded' : 'PinGate ready', 'OK', 'fg=black;bg=green', ' ', true);

        if ($token !== '') {
            $reused = (bool) ($data['token_reused'] ?? false);
            $io->section($reused ? 'Local token (reused from overlay)' : 'Local token (written to overlay)');
            $io->writeln('  <info>' . self::escape($token) . '</info>');
            if ($reused) {
                $io->writeln('  <fg=gray>New local token:</> <comment>php pinoox pinroll:gate ' . $target . ' --rotate</comment>');
            } else {
                $overlay = (string) ($data['overlay_path'] ?? $data['env_path'] ?? '.pinoox/pinroll.config.php');
                $io->writeln('  <fg=gray>Saved to</> <comment>' . self::escape(self::relPath($overlay)) . '</comment> <fg=gray>as gate.token</>');
            }
        }

        if (!$embedToken) {
            $io->section('Host auth (multi-developer)');
            $io->writeln([
                '  <fg=gray>pingate.php has</> <comment>no embedded token</comment> <fg=gray>(safe to redeploy).</>',
                '  Each developer uploads their own file:',
                '  <comment>storage/pinroll/tokens/{label}.php</comment>',
                '  Mint: <comment>php pinoox pinroll:token {label}</comment>',
            ]);
        } else {
            $io->section('Host auth (legacy embed)');
            $io->writeln('  <fg=yellow>Token hash is embedded in pingate.php — redeploy may affect single-hash setups.</>');
        }

        $site = (string) ($data['site'] ?? '');
        if ($site === '' && $displayUrl !== '') {
            $site = \Pinoox\Pinroll\Console\GateUrl::siteFrom($displayUrl);
        }
        if ($site !== '') {
            $io->section('Site origin');
            $io->writeln('  <comment>' . self::escape($site) . '</comment>');
        }

        if ($displayUrl !== '') {
            $io->section($isExample ? 'PinGate URL (example — use your real domain)' : 'PinGate URL');
            $io->writeln('  <comment>' . self::escape($displayUrl) . '</comment>');
        }

        $io->section('.pinoox/pinroll.config.php');
        $io->writeln([
            "  <fg=gray>'gate' => [</>",
            "      'site' => '" . self::escape($site !== '' ? $site : 'https://example.com') . "',",
            "      'token' => '" . ($token !== '' ? self::escape($token) : '<your-local-token>') . "',",
            '  ],',
            '  <fg=gray>Local plain token for CLI — host uses storage/pinroll/tokens/{label}.php</>',
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
                '  <fg=gray>Files</>   pingate.php next to platform vendor/',
            ]);
        } elseif (!empty($data['entry']) || !empty($data['gate_dir'])) {
            $io->section('Local files (no FTP upload)');
            $io->writeln([
                '  <fg=gray>Entry</> <comment>storage/pinroll/pingate.php</comment>',
                '  <fg=gray>Copy to</> <comment>' . self::escape($extractTo) . '</comment> as pingate.php',
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

        $hostArg = self::hostCliSuffix($target);
        $hostKey = ConfigWriter::envKeyFor($target, 'host', 'ftp');
        $userKey = ConfigWriter::envKeyFor($target, 'user', 'ftp');
        $passKey = ConfigWriter::envKeyFor($target, 'password', 'ftp');

        $io->newLine();
        $io->section('Next steps');
        $io->writeln([
            '  <fg=yellow>1.</> Set FTP credentials in <comment>.pinoox/pinroll.config.php</comment> or <comment>.env</comment>:',
            '       ' . $hostKey . '=',
            '       ' . $userKey . '=',
            '       ' . $passKey . '=',
            '',
            '  <fg=yellow>2.</> Blank host — first install:',
            '       <comment>php pinoox pinroll:provision' . $hostArg . '</comment>',
            '',
            '  <fg=yellow>3.</> Existing site — connect & upload PinGate:',
            '       <comment>php pinoox pinroll:connect' . $hostArg . '</comment>',
            '       <fg=gray>Writes site origin + shared token into the overlay (not .env).</>',
            '',
            '  <fg=yellow>4.</> Go live / update:',
            '       <comment>php pinoox pinroll:deploy' . $hostArg . '</comment>',
            '       <fg=gray>or platform + all apps:</> <comment>php pinoox pinroll:deploy --full' . $hostArg . '</comment>',
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

        self::printCheckHints($io, $check['checks'] ?? []);

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
            $io->writeln('  <fg=gray>Fix the steps above, then re-run</> <comment>php pinoox pinroll:check' . $hostArg . '</comment>');
            $io->writeln('  <fg=gray>or reconnect:</> <comment>php pinoox pinroll:connect' . $hostArg . ' --reset</comment>');
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

        self::printCheckHints($io, $result['checks'] ?? []);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function printResolvedConfig(SymfonyStyle $io, array $row): void
    {
        $name = (string) ($row['host'] ?? 'unknown');
        $io->writeln('<info>' . self::escape($name) . '</info>');
        $io->writeln('  <fg=gray>via</>         <comment>' . self::escape((string) ($row['via'] ?? '')) . '</comment>');
        $io->writeln('  <fg=gray>deploy_path</> <comment>' . self::escape((string) ($row['deploy_path'] ?? '')) . '</comment>');
        if ((string) ($row['web_path'] ?? '') !== '') {
            $io->writeln('  <fg=gray>web_path</>    <comment>' . self::escape((string) $row['web_path']) . '</comment>');
        }
        $io->writeln('  <fg=gray>site</>        <comment>' . self::escape((string) ($row['site'] ?? '')) . '</comment>');
        $io->writeln('  <fg=gray>gate</>        <comment>' . self::escape((string) ($row['gate_url'] ?? '')) . '</comment>');
        $token = (string) ($row['token_redacted'] ?? '');
        $io->writeln('  <fg=gray>token</>       <comment>' . self::escape($token !== '' ? $token : '(empty)') . '</comment>');
        if ((string) ($row['ftp_host'] ?? '') !== '') {
            $io->writeln('  <fg=gray>ftp</>         <comment>' . self::escape((string) $row['ftp_host']) . '</comment>'
                . ((string) ($row['ftp_user'] ?? '') !== '' ? ' <fg=gray>(' . self::escape((string) $row['ftp_user']) . ')</>' : ''));
        }
        $io->newLine();
    }

    /**
     * @param list<mixed> $checks
     */
    private static function printCheckHints(SymfonyStyle $io, array $checks): void
    {
        $printed = false;
        foreach ($checks as $check) {
            if (!is_array($check) || ($check['ok'] ?? false)) {
                continue;
            }

            $hints = $check['hints'] ?? null;
            if (!is_array($hints) || $hints === []) {
                continue;
            }

            if (!$printed) {
                $io->newLine();
                $io->writeln('  <comment>How to fix</comment>');
                $printed = true;
            }

            foreach ($hints as $line) {
                $io->writeln('  ' . self::escape((string) $line));
            }
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
