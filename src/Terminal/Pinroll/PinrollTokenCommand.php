<?php

namespace Pinoox\Terminal\Pinroll;

use Pinoox\Component\Terminal;
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
    description: 'Mint a deploy token and write storage/pinroll/tokens/{label}.php for host upload',
    aliases: ['pinroll:gate:token'],
)]
class PinrollTokenCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->addArgument('label', InputArgument::REQUIRED, 'Developer label (e.g. yousef, ali)')
            ->addArgument('host', InputArgument::OPTIONAL, 'Host name (omit when default_host is set)')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host override')
            ->addOption('rotate', null, InputOption::VALUE_NONE, 'Always mint a new token (default: reuse gate.token from config)')
            ->addOption('deploy', null, InputOption::VALUE_NONE, 'Deprecated: use pinroll:gate for pingate.php upload');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);

        if ((bool) $input->getOption('deploy')) {
            $io->warning('Use pinroll:gate for pingate.php upload. pinroll:token only creates storage/pinroll/tokens/{label}.php.');
        }

        try {
            $root = defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd();
            $label = (string) $input->getArgument('label');
            $hostName = PinrollInput::hostName($input);
            $rotate = (bool) $input->getOption('rotate');

            Pinroll::boot(new NativePathResolver((string) $root));
            $raw = Pinroll::hosts()->raw($hostName);
            $gate = HostGate::credentials($raw);

            $token = (!$rotate && $gate['token'] !== '') ? $gate['token'] : TokenGenerator::token();
            $tokenReused = !$rotate && $gate['token'] !== '' && $token === $gate['token'];

            $site = $gate['site'] !== '' ? $gate['site'] : GateUrl::siteFrom($gate['url']);
            if ($site === '') {
                $site = 'https://example.com';
            }

            OverlayWriter::persistGate((string) $root, $hostName, $site, $token);

            $tokenPath = GateTokenRegistry::writeTokenFile((string) $root, $label, $token);
            $hostPath = GateTokenRegistry::hostUploadPath($label);
            $relToken = self::relPath((string) $root, $tokenPath);

            $io->newLine();
            $io->block('Token ready', 'OK', 'fg=black;bg=green', ' ', true);

            $io->section('Local config');
            $io->writeln([
                '  <fg=gray>Plain token saved to</> <comment>.pinoox/pinroll.config.php</comment> <fg=gray>(gate.token)</>',
                $tokenReused
                    ? '  <fg=gray>Reused existing gate.token — pass</> <comment>--rotate</comment> <fg=gray>to mint a new one.</>'
                    : '  <fg=gray>New token minted for host</> <comment>' . $hostName . '</comment>',
            ]);

            $io->section('Your token (local only — do not upload)');
            $io->writeln('  <info>' . $token . '</info>');

            $io->section('Host file (upload this)');
            $io->writeln([
                '  <fg=gray>Local</>   <comment>' . $relToken . '</comment>',
                '  <fg=gray>Host</>   <comment>' . $hostPath . '</comment>',
                '  <fg=gray>Upload via cPanel / SFTP — pingate.php does not need redeploy.</>',
            ]);

            if ($gate['url'] !== '') {
                $io->section('PinGate');
                $io->writeln('  <comment>' . $gate['url'] . '</comment>');
            }

            $io->newLine();
            $io->writeln('  <fg=gray>Check:</> <comment>php pinoox pinroll:check --via=pinion</comment>');
            $io->writeln('  <fg=gray>Deploy:</> <comment>php pinoox pinroll:deploy --via=pinion</comment>');

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
