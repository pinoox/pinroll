<?php

namespace Pinoox\Pinroll\Bridge;

/**
 * Thin Portal-style facade when running inside a Pinoox platform.
 */
final class PinrollPortal
{
    public static function deploy(string $target, array $options = []): array
    {
        return (new \Pinoox\Pinroll\Console\DeployRunner())->deploy($target, $options);
    }

    public static function engine(): \Pinoox\Pinroll\Rollout\RolloutEngine
    {
        return \Pinoox\Pinroll\Pinroll::engine();
    }

    public static function gate(): \Pinoox\Pinroll\PinGate\PinGateHttpHandler
    {
        return \Pinoox\Pinroll\Pinroll::gate();
    }
}
