<?php

namespace Pinoox\Terminal\Pinroll;

use Pinoox\Component\Terminal;
use Pinoox\Pinroll\Console\PinrollCli;
use Pinoox\Pinroll\Console\ProvisionRunner;
use Pinoox\Pinroll\Host\HostSelector;
use Pinoox\Pinroll\Support\PushConsole;
use Pinoox\Pinroll\Support\PushProgress;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pinroll:provision',
    description: 'Install Pinoox on a blank host (PinGate + platform.zip + database/admin setup)',
)]
class PinrollProvisionCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->addArgument('host', InputArgument::OPTIONAL, 'Host name')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host override')
            ->addOption('via', null, InputOption::VALUE_REQUIRED, 'Transport: ftp or ssh')
            ->addOption('setup-only', null, InputOption::VALUE_NONE, 'Skip zip upload/extract; only run database + admin setup')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite an existing extract / re-run setup after installer is disabled')
            ->addOption('reupload', null, InputOption::VALUE_NONE, 'Rebuild and re-upload platform.zip even if the host already has files')
            ->addOption('lang', null, InputOption::VALUE_REQUIRED, 'Installer language: en or fa')
            ->addOption('db-host', null, InputOption::VALUE_REQUIRED, 'Database host (PINROLL_DB_HOST)')
            ->addOption('db-database', null, InputOption::VALUE_REQUIRED, 'Database name (PINROLL_DB_DATABASE)')
            ->addOption('db-username', null, InputOption::VALUE_REQUIRED, 'Database username')
            ->addOption('db-password', null, InputOption::VALUE_REQUIRED, 'Database password')
            ->addOption('db-connection', null, InputOption::VALUE_REQUIRED, 'mysql, mariadb, pgsql, or sqlsrv')
            ->addOption('db-port', null, InputOption::VALUE_REQUIRED, 'Database port')
            ->addOption('db-prefix', null, InputOption::VALUE_REQUIRED, 'Table prefix')
            ->addOption('db-timezone', null, InputOption::VALUE_REQUIRED, 'Database timezone')
            ->addOption('admin-fname', null, InputOption::VALUE_REQUIRED, 'Admin first name')
            ->addOption('admin-lname', null, InputOption::VALUE_REQUIRED, 'Admin last name')
            ->addOption('admin-email', null, InputOption::VALUE_REQUIRED, 'Admin email')
            ->addOption('admin-username', null, InputOption::VALUE_REQUIRED, 'Admin username')
            ->addOption('admin-password', null, InputOption::VALUE_REQUIRED, 'Admin password');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);

        PushProgress::bind(
            function (string $message, string $style = PushConsole::STYLE_DEFAULT) use ($io): void {
                $formatted = PushConsole::format($message, $style);
                if ($formatted === '') {
                    $io->newLine();

                    return;
                }

                $io->writeln($formatted);
            },
            $output->isVerbose(),
        );

        try {
            $root = defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd();
            $hostName = HostSelector::resolve($input, (string) ($input->getArgument('host') ?? ''));

            $result = (new ProvisionRunner((string) $root))->run($io, $hostName, [
                'via' => (string) ($input->getOption('via') ?: ''),
                'interactive' => $input->isInteractive(),
                'setup_only' => (bool) $input->getOption('setup-only'),
                'force' => (bool) $input->getOption('force'),
                'reupload' => (bool) $input->getOption('reupload'),
                'lang' => $input->getOption('lang'),
                'db_host' => $input->getOption('db-host'),
                'db_database' => $input->getOption('db-database'),
                'db_username' => $input->getOption('db-username'),
                'db_password' => $input->getOption('db-password'),
                'db_connection' => $input->getOption('db-connection'),
                'db_port' => $input->getOption('db-port'),
                'db_prefix' => $input->getOption('db-prefix'),
                'db_timezone' => $input->getOption('db-timezone'),
                'admin_fname' => $input->getOption('admin-fname'),
                'admin_lname' => $input->getOption('admin-lname'),
                'admin_email' => $input->getOption('admin-email'),
                'admin_username' => $input->getOption('admin-username'),
                'admin_password' => $input->getOption('admin-password'),
            ]);

            PinrollCli::printProvisionResult($io, $result);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } finally {
            PushProgress::bind(null);
        }
    }
}
