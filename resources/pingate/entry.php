<?php

/**
 * PinGate HTTP entry — generated as a single pingate.php next to index.php.
 */
declare(strict_types=1);

$PINROLL_GATE = [];

if (defined('PINROLL_GATE_AS_CONFIG')) {
    return $PINROLL_GATE;
}

($run = require __DIR__ . '/bootstrap.php');
if (is_callable($run)) {
    $run(__DIR__);
} else {
    pinroll_pingate_run(__DIR__, $PINROLL_GATE);
}
