<?php

use Pinoox\Pinroll\Host\HostSelector;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputArgument;

test('host selector does not require legacy target argument', function () {
    $definition = new InputDefinition([
        new InputArgument('host', InputArgument::OPTIONAL),
    ]);

    $input = new ArrayInput(['host' => 'production'], $definition);

    expect(HostSelector::argument($input, 'target'))->toBe('')
        ->and(HostSelector::argument($input, 'host'))->toBe('production');
});

test('host selector reads legacy target argument when defined', function () {
    $definition = new InputDefinition([
        new InputArgument('target', InputArgument::OPTIONAL),
    ]);

    $input = new ArrayInput(['target' => 'staging'], $definition);

    expect(HostSelector::argument($input, 'target'))->toBe('staging');
});
