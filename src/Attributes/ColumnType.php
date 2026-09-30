<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Attributes;

use Attribute;
use Illuminate\Database\Schema\Blueprint;

#[Attribute(Attribute::TARGET_PROPERTY)]
class ColumnType
{
    /** @param list<mixed> $args */
    public function apply(Blueprint $Blueprint, string $method, array $args): mixed
    {
        return $Blueprint->{$method}(...$args);
    }
}
