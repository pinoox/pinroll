<?php

namespace Pinoox\Pinroll\Contract;

interface PathResolverInterface
{
    public function root(): string;

    public function storage(string $relative = ''): string;

    public function config(string $name): string;

    public function bundle(string $name): string;
}
