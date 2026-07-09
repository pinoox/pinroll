<?php

namespace Pinoox\Terminal\Pinroll;

use Pinoox\Component\Terminal;
use Pinoox\Pinroll\Console\DeployRunner;
use Pinoox\Pinroll\Console\PinrollCli;
use Pinoox\Pinroll\Console\ProjectPreparer;
use Pinoox\Pinroll\Console\TargetHostSetup;
use Pinoox\Pinroll\Pinroll;
use Pinoox\Pinroll\Support\HostDir;
use Pinoox\Pinroll\Support\NativePathResolver;
use Pinoox\Pinroll\Support\TokenGenerator;
use Pinoox\Pinroll\Target\TargetGate;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pinroll:gate:token',
    description: 'Generate a PinGate token and show top-level gate config snippet',
)]
class PinrollGateTokenCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->addArgument('target', InputArgument::OPTIONAL, 'Target name', 'production')
            ->addOption('deploy', null, InputOption::VALUE_NONE, 'Also run pinroll:gate (FTP upload / optional -z)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);

        try {
            $root = defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd();
            $target = (string) $input->getArgument('target');
            $withDeploy = (bool) $input->getOption('deploy');

            $paths = new NativePathResolver((string) $root);
            Pinroll::configure([], $paths);
            $raw = Pinroll::targets()->raw($target);

            if ($withDeploy) {
                $host = TargetHostSetup::resolveForGateInit($io, $input, (string) $root, $target);
                $gate = (new DeployRunner((string) $root))->initGate(
                    $target,
                    false,
                    $host['dir'],
                    $host['gate_url'] !== '' ? $host['gate_url'] : null,
                );

                PinrollCli::printGateInitResult($io, array_merge(['target' => $target], $gate));

                return Command::SUCCESS;
            }

            $token = TokenGenerator::token();
            $keys = ProjectPreparer::envKeysForTarget($target);
            $dir = HostDir::fromTarget($raw);

            PinrollCli::printGateInitResult($io, [
                'target' => $target,
                'token' => $token,
                'gate_url' => TargetGate::exampleUrl($dir !== '' ? $dir : null),
                'gate_url_is_example' => true,
                'dir' => $dir,
                'url_key' => $keys['url'],
                'token_key' => $keys['token'],
            ]);

            $io->note([
                'This token is for .env / top-level gate { url, token } only.',
                'First-time setup: php pinoox pinroll:gate (FTP upload when configured).',
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
