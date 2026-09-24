<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelPackage\Tests\Fixtures\PublicApi;

trait Named
{
    public function name(): string
    {
        return static::class;
    }
}
