<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Attributes;

use Attribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Paginate extends Terminal
{
    /** @param  Builder<Model>|Relation<Model, Model, mixed>  $builder */
    public function execute(Builder|Relation $builder, string $method, mixed $args): mixed
    {
        if (is_array($args)) {
            return $builder->{$method}(...$args);
        }

        return is_int($args)
            ? $builder->{$method}($args)
            : $builder->{$method}();
    }
}
