<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Attributes;

use Attribute;
use Illuminate\Database\Schema\Blueprint;

#[Attribute(Attribute::TARGET_PROPERTY)]
class TableOption
{
    public function apply(Blueprint $blueprint, string $method, mixed $value): void
    {
        if ($value === true || $value === null) {
            $blueprint->{$method}();
        } else {
            $blueprint->{$method}($value);
        }
    }
}
