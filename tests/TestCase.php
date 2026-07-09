<?php

namespace Pinoox\Pinroll\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Pinoox\Pinroll\Pinroll;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $dir = sys_get_temp_dir() . '/pinroll-test-' . uniqid('', true);
        mkdir($dir, 0755, true);

        Pinroll::configure([
            'storage_path' => $dir,
        ]);
    }
}
