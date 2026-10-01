<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Attributes\Attributes;

use Attribute;
use Illuminate\Database\Schema\Blueprint;

#[Attribute(Attribute::TARGET_PROPERTY)]
class TableConstraint
{
    public function apply(Blueprint $Blueprint, string $method, mixed $args): mixed
    {
        if (is_array($args) && isset($args['columns'])) {
            return $Blueprint->{$method}($args['columns'], $args['name'] ?? null);
        }

        return $Blueprint->{$method}($args);
    }
}
