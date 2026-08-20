<?php

namespace Pinoox\Terminal\Pinroll;

use Pinoox\Component\Terminal;
use Pinoox\Pinroll\Console\GateTokenSyncer;
use Pinoox\Pinroll\Console\GateUrl;
use Pinoox\Pinroll\Console\OverlayWriter;
use Pinoox\Pinroll\Console\PinrollInput;
use Pinoox\Pinroll\Host\HostGate;
use Pinoox\Pinroll\PinGate\GateTokenRegistry;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\TokenGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pinroll:token',
    description: 'Mint a deploy token and write storage/pinroll/tokens/{label}.php (optional FTP/SSH push)',
    aliases: ['pinroll:gate:token'],
)]
class PinrollTokenCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->addArgument('label', InputArgument::OPTIONAL, 'Developer label (default: OS username / gate.label)')
            ->addArgument('host', InputArgument::OPTIONAL, 'Host name (omit when default_host is set)')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host override')
            ->addOption('label', null, InputOption::VALUE_REQUIRED, 'Developer label override')
            ->addOption('rotate', null, InputOption::VALUE_NONE, 'Always mint a new token (default: reuse gate.token from config)')
            ->addOption('push', null, InputOption::VALUE_NONE, 'Upload token file via FTP/SSH when credentials are configured')
            ->addOption('deploy', null, InputOption::VALUE_NONE, 'Deprecated: use --push');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);

        try {
            $root = defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd();
            $hostName = PinrollInput::hostName($input);
            $rotate = (bool) $input->getOption('rotate');
            $push = (bool) $input->getOption('push') || (bool) $input->getOption('deploy');

            Pinroll::boot(new NativePathResolver((string) $root));
            $raw = Pinroll::hosts()->raw($hostName);
            $resolved = Pinroll::hosts()->resolve($hostName);
            $gate = HostGate::credentials($raw);

            $labelOpt = (string) ($input->getOption('label') ?: '');
            $labelArg = (string) ($input->getArgument('label') ?? '');
            $label = $labelOpt !== '' ? $labelOpt : ($labelArg !== '' ? $labelArg : GateTokenRegistry::labelFromHost($raw));

            $token = (!$rotate && $gate['token'] !== '') ? $gate['token'] : TokenGenerator::token();
            $tokenReused = !$rotate && $gate['token'] !== '' && $token === $gate['token'];

            $site = $gate['site'] !== '' ? $gate['site'] : GateUrl::siteFrom($gate['url']);
            if ($site === '') {
                $site = 'https://example.com';
            }

            if ($push && GateTokenSyncer::canUpload($resolved, $raw)) {
                $sync = GateTokenSyncer::sync((string) $root, $hostName, $resolved, $raw, $token, $label);
            } else {
                OverlayWriter::persistGate((string) $root, $hostName, $site, $token, null, $label);
                $path = GateTokenRegistry::writeTokenFile((string) $root, $label, $token);
                $sync = [
                    'label' => $label,
                    'local' => $path,
                    'remote' => GateTokenRegistry::hostUploadPath($label),
                    'uploaded' => false,
                ];
            }

            $relToken = self::relPath((string) $root, $sync['local']);

            $io->newLine();
            $io->block($sync['uploaded'] ? 'Token uploaded' : 'Token ready', 'OK', 'fg=black;bg=green', ' ', true);

            $io->section('Local config');
            $io->writeln([
                '  <fg=gray>Plain token saved to</> <comment>.pinoox/pinroll.config.php</comment> <fg=gray>(gate.token + gate.label)</>',
                $tokenReused
                    ? '  <fg=gray>Reused existing gate.token — pass</> <comment>--rotate</comment> <fg=gray>to mint a new one.</>'
                    : '  <fg=gray>New token minted for host</> <comment>' . $hostName . '</comment>',
            ]);

            $io->section('Your token (local only — do not upload)');
            $io->writeln('  <info>' . $token . '</info>');

            $io->section('Host file');
            $io->writeln([
                '  <fg=gray>Local</>   <comment>' . $relToken . '</comment>',
                '  <fg=gray>Host</>   <comment>' . $sync['remote'] . '</comment>',
                $sync['uploaded']
                    ? '  <fg=green>Uploaded via FTP/SSH</>'
                    : '  <fg=gray>Upload:</> <comment>php pinoox pinroll:token ' . $label . ' --push</comment>',
            ]);

            if ($gate['url'] !== '') {
                $io->section('PinGate');
                $io->writeln('  <comment>' . $gate['url'] . '</comment>');
            }

            $io->newLine();
            $io->writeln('  <fg=gray>Check:</> <comment>php pinoox pinroll:check --via=pinion</comment>');
            $io->writeln('  <fg=gray>Deploy:</> <comment>php pinoox pinroll:deploy</comment>');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    private static function relPath(string $root, string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, $root . '/')) {
            return substr($path, strlen($root) + 1);
        }

        return $path;
    }
}
