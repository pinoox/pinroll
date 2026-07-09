<?php

namespace Pinoox\Pinroll\Bridge;

/**
 * Integration notes for pincore (when a pincore PR is available):
 *
 * - Add "pinoox/pinroll": "^1.0" to pincore/composer.json
 * - Register Pinroll Terminal commands in Component/Kernel/Terminal::loadComposerTerminals()
 * - Optional: Component/Pinroll/PinrollConfig.php, Portal/Pinroll.php, config/pinroll.config.php
 *
 * Until upstream pincore registers Pinroll commands in loadComposerTerminals(),
 * ensure pinoox/pinroll is required and Terminal.php lists Pinoox\Terminal\Pinroll\* commands.
 */
final class PincoreIntegration
{
}
