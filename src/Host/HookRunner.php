<?php

namespace Pinoox\Pinroll\Host;

use Pinoox\Pinroll\Exception\PinrollException;
use Pinoox\Pinroll\Rollout\RolloutSession;

final class HookRunner
{
    /**
     * @param array<string, mixed> $host
     * @param list<string> $hookNames
     */
    public static function run(array $host, array $hookNames, RolloutSession $session, string $cwd, bool $remote = false): void
    {
        $hooks = is_array($host['hooks'] ?? null) ? $host['hooks'] : [];
        $failOnError = !isset($host['hooks_fail_on_error']) || (bool) $host['hooks_fail_on_error'];

        foreach ($hookNames as $hookName) {
            $commands = $hooks[$hookName] ?? [];
            if (!is_array($commands) || $commands === []) {
                continue;
            }

            foreach ($commands as $command) {
                if (!is_string($command) || trim($command) === '') {
                    continue;
                }

                $label = ($remote ? 'remote:' : 'local:') . $hookName;
                $code = self::runCommand($command, $cwd);
                $status = $code === 0 ? 'ok' : 'failed';
                $session->addStep('hook:' . $hookName, $status, $command);

                if ($code !== 0 && $failOnError) {
                    throw new PinrollException('Hook failed (' . $label . '): ' . $command);
                }
            }
        }
    }

    private static function runCommand(string $command, string $cwd): int
    {
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptor, $pipes, $cwd);
        if (!is_resource($process)) {
            return 1;
        }

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        return proc_close($process);
    }
}
