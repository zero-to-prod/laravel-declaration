<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Contracts;

interface Clock
{
    public function now(): string;
}
