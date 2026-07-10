<?php

namespace Pinoox\Pinroll\Bridge;

/**
 * Pinroll CLI commands registered by pincore (same pattern as DevDB).
 *
 * @return list<class-string>
 */
final class PinrollCommands
{
    public static function all(): array
    {
        return [
            \Pinoox\Terminal\Pinroll\PinrollInitCommand::class,
            \Pinoox\Terminal\Pinroll\PinrollConnectCommand::class,
            \Pinoox\Terminal\Pinroll\PinrollAppsCommand::class,
            \Pinoox\Terminal\Pinroll\PinrollCheckCommand::class,
            \Pinoox\Terminal\Pinroll\PinrollPushCommand::class,
            \Pinoox\Terminal\Pinroll\PinrollDeployCommand::class,
            \Pinoox\Terminal\Pinroll\PinrollGateInitCommand::class,
            \Pinoox\Terminal\Pinroll\PinrollGateTokenCommand::class,
            \Pinoox\Terminal\Pinroll\PinrollVendorPackCommand::class,
            \Pinoox\Terminal\Pinroll\PinrollBuildCommand::class,
            \Pinoox\Terminal\Pinroll\PinrollStatusCommand::class,
            \Pinoox\Terminal\Pinroll\PinrollHistoryCommand::class,
            \Pinoox\Terminal\Pinroll\PinrollRollbackCommand::class,
            \Pinoox\Terminal\Pinroll\PinrollCleanupCommand::class,
            \Pinoox\Terminal\Pinroll\PinrollInstallCommand::class,
            \Pinoox\Terminal\Pinroll\PinrollPublishCommand::class,
            \Pinoox\Terminal\Pinroll\PinrollPullCommand::class,
            \Pinoox\Terminal\Pinroll\PinrollMigrateConfigCommand::class,
            \Pinoox\Terminal\Pinroll\PinrollMigrateDryRunCommand::class,
        ];
    }
}
