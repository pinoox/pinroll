<?php

namespace Pinoox\Pinroll\Console;

use InvalidArgumentException;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\ConfigFileLoader;
use Pinoox\Pinroll\Support\HostDir;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\PinrollAutoloader;
use Pinoox\Pinroll\Target\TargetChecker;
use Pinoox\Pinroll\Target\TargetGate;
use Symfony\Component\Console\Style\SymfonyStyle;

final class GateSetupWizard
{
    /**
     * @return array{
     *     gate_configured: bool,
     *     ready_for_push: bool,
     *     gate_zip?: string
     * }
     */
    public static function run(
        SymfonyStyle $io,
        string $projectRoot,
        string $targetName,
        string $configFile,
    ): array {
        PinrollAutoloader::register($projectRoot);
        Pinroll::configure([], new NativePathResolver($projectRoot));

        $raw = Pinroll::targets()->raw($targetName);
        $via = (string) ($raw['via'] ?? 'ftp');

        if ($via === 'local') {
            return ['gate_configured' => true, 'ready_for_push' => true];
        }

        if (TargetGate::isConfigured($raw)) {
            if (!$io->confirm('PinGate is already configured. Run setup again?', false)) {
                return self::verifyReady($io, $projectRoot, $targetName, null);
            }
        }

        $io->section('PinGate — remote install');
        $io->text([
            'PinGate installs staged releases on the host over HTTP (pinroll:deploy / pinroll:install).',
            'With FTP configured, files upload automatically (no zip). Token + URL go to .env.',
            'Host needs platform vendor with pinoox/pinroll — export with: php pinoox pinroll:vendor',
        ]);

        if (!$io->confirm('Set up PinGate now?', true)) {
            $io->warning('Without PinGate you can push but not install remotely. Run: php pinoox pinroll:gate ' . $targetName);

            return ['gate_configured' => false, 'ready_for_push' => false];
        }

        $loaded = ConfigFileLoader::load($configFile);
        /** @var array<string, array<string, mixed>> $targets */
        $targets = is_array($loaded['targets'] ?? null) ? $loaded['targets'] : [];
        $target = $targets[$targetName] ?? $raw;
        $dir = HostDir::fromTarget($target);
        $web = HostDir::webPath($dir);

        $siteUrl = trim((string) $io->ask(
            'Public site URL (e.g. https://pinoox.com)',
            'https://' . TargetGate::EXAMPLE_DOMAIN . ($web !== '' ? '/' . $web : ''),
        ));

        // Derive PinGate URL from the site URL — do not ask again (that looked like a hang).
        [, $gateUrl] = self::parseSiteUrl($siteUrl, $dir);
        $io->writeln('  <fg=gray>PinGate URL:</> <comment>' . $gateUrl . '</comment>');

        $io->writeln('  <fg=gray>Building PinGate files (copying vendor — may take a minute)…</>');
        $gate = (new DeployRunner($projectRoot))->initGate($targetName, false, $dir, $gateUrl);
        self::mergeGateIntoConfig($configFile, $targetName, $targets);

        PinrollCli::printGateInitResult($io, array_merge(['target' => $targetName], $gate));

        self::promptEnvSetup($io, $projectRoot, $targetName, $gateUrl, (string) $gate['token']);
        self::promptUpload($io, $gate);

        return self::verifyReady($io, $projectRoot, $targetName, $gate);
    }

    /**
     * @return array{0: string, 1: string} [dir, suggestedGateUrl]
     */
    private static function parseSiteUrl(string $input, string $fallbackDir): array
    {
        $input = trim($input);
        if ($input === '') {
            return [$fallbackDir, TargetGate::exampleUrl($fallbackDir !== '' ? $fallbackDir : null)];
        }

        try {
            $normalized = GateUrl::normalizeInput($input, $fallbackDir !== '' ? $fallbackDir : null);
            $dir = HostDir::dirFromGateUrl($normalized);

            return [$dir, $normalized];
        } catch (InvalidArgumentException) {
            try {
                $domain = GateUrl::normalizeDomain($input);

                return [$fallbackDir, GateUrl::fromDomain($domain, $fallbackDir !== '' ? $fallbackDir : null)];
            } catch (InvalidArgumentException $e) {
                throw new \RuntimeException($e->getMessage());
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $targets
     */
    private static function mergeGateIntoConfig(
        string $configFile,
        string $targetName,
        array $targets,
    ): void {
        $keys = ProjectPreparer::envKeysForTarget($targetName);

        if (!isset($targets[$targetName]) || !is_array($targets[$targetName])) {
            $targets[$targetName] = SampleConfig::productionTarget($targetName);
        }

        // Remove legacy nested ftp.gate
        if (isset($targets[$targetName]['ftp']['gate'])) {
            unset($targets[$targetName]['ftp']['gate']);
        }

        $targets[$targetName]['gate'] = [
            'url' => ['_env' => $keys['url'], 'default' => ''],
            'token' => ['_env' => $keys['token'], 'default' => ''],
        ];

        ConfigWriter::write($configFile, $targets);
        Pinroll::configure([], new NativePathResolver(dirname($configFile, 2)));
    }

    private static function promptEnvSetup(
        SymfonyStyle $io,
        string $projectRoot,
        string $targetName,
        string $gateUrl,
        string $token,
    ): void {
        $keys = ProjectPreparer::envKeysForTarget($targetName);
        $envPath = rtrim($projectRoot, '/') . '/.env';

        $io->section('.env');
        $io->writeln([
            '  <fg=yellow>' . $keys['url'] . '</>=' . $gateUrl,
            '  <fg=yellow>' . $keys['token'] . '</>=' . $token,
        ]);

        if ($io->confirm('Write PinGate URL and token to .env now?', true)) {
            EnvFileWriter::merge($envPath, [
                $keys['url'] => $gateUrl,
                $keys['token'] => $token,
            ]);
            $io->success('Updated ' . self::relPath($projectRoot, $envPath));
        }
    }

    /**
     * @param array<string, mixed> $gate
     */
    private static function promptUpload(SymfonyStyle $io, array $gate): void
    {
        if (!empty($gate['uploaded'])) {
            $io->note('PinGate was uploaded via FTP. Local gate files were removed.');

            return;
        }

        $zip = (string) ($gate['zip'] ?? '');
        $extractTo = (string) ($gate['extract_to'] ?? '');

        if ($zip !== '') {
            $io->section('Upload PinGate to server');
            $io->listing([
                'Pinoox platform (vendor/, apps/) must already be at the deploy root',
                'Upload ' . PinrollCli::relPath($zip) . ' via FTP / file manager',
                'Extract into ' . $extractTo . ' (pingate.php + gate/ next to vendor/)',
            ]);

            if (!$io->confirm('Have you uploaded and extracted the PinGate zip?', false)) {
                $io->note('Continue after upload: php pinoox pinroll:check');
            }

            return;
        }

        if (!empty($gate['entry']) || !empty($gate['gate_dir'])) {
            $io->section('Copy PinGate to server');
            $io->listing([
                'Copy pinroll/pingate.php and pinroll/gate/ to ' . $extractTo,
                'Or re-run with FTP configured: php pinoox pinroll:gate',
            ]);
        }
    }

    /**
     * @param array<string, mixed>|null $gate
     * @return array{gate_configured: bool, ready_for_push: bool, gate_zip?: string}
     */
    private static function verifyReady(
        SymfonyStyle $io,
        string $projectRoot,
        string $targetName,
        ?array $gate,
    ): array {
        Pinroll::configure([], new NativePathResolver($projectRoot));
        $raw = Pinroll::targets()->raw($targetName);
        $gateConfigured = TargetGate::isConfigured($raw);

        $io->section('Verify setup');
        $check = (new TargetChecker($projectRoot))->check($targetName);
        PinrollCli::printCheckResult($io, $check);

        if (!$check['ok'] && $io->confirm('Run check again after fixing?', false)) {
            $check = (new TargetChecker($projectRoot))->check($targetName);
            PinrollCli::printCheckResult($io, $check);
        }

        $ready = $gateConfigured && ($check['ok'] ?? false);

        if ($ready) {
            $io->success('Setup complete — ready to go live');
            $io->writeln('  <comment>php pinoox pinroll:deploy ' . $targetName . '</comment>');
        } elseif ($gateConfigured) {
            $io->note([
                'Config and .env are set. Finish host upload if needed, then:',
                'php pinoox pinroll:check ' . $targetName,
                'php pinoox pinroll:deploy ' . $targetName,
            ]);
        }

        $result = [
            'gate_configured' => $gateConfigured,
            'ready_for_push' => $ready,
        ];

        if (is_array($gate) && !empty($gate['zip'])) {
            $result['gate_zip'] = (string) $gate['zip'];
        }

        return $result;
    }

    private static function relPath(string $projectRoot, string $absolute): string
    {
        $root = rtrim($projectRoot, '/') . '/';

        if (str_starts_with($absolute, $root)) {
            return substr($absolute, strlen($root));
        }

        return $absolute;
    }
}
