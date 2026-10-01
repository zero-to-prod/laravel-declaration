<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Attributes\Attributes;

use Attribute;
use Illuminate\Database\Schema\Blueprint;

#[Attribute(Attribute::TARGET_PROPERTY)]
class TableOption
{
    public function apply(Blueprint $Blueprint, string $method, mixed $value): void
    {
        if ($value === true || $value === null) {
            $Blueprint->{$method}();
        } else {
            $Blueprint->{$method}($value);
        }
    }
}
