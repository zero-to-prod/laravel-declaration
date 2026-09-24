<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\PublicApi;

trait Named
{
    public function name(): string
    {
        return static::class;
    }
}
