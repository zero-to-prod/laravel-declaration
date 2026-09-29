<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Attributes;

use Attribute;
use Illuminate\Database\Schema\Blueprint;

#[Attribute(Attribute::TARGET_PROPERTY)]
class TableConstraint
{
    public function apply(Blueprint $blueprint, string $method, mixed $args): mixed
    {
        if (is_array($args) && isset($args['columns'])) {
            return $blueprint->{$method}($args['columns'], $args['name'] ?? null);
        }

        return $blueprint->{$method}($args);
    }
}
