<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Attributes;

use Attribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Flag extends Clause
{
    /**
     * @param  Builder<Model>|Relation<Model, Model, mixed>  $builder
     * @param  array<string, mixed>  $parameters
     */
    public function apply(Builder|Relation $builder, string $method, mixed $args, array $parameters = []): void
    {
        if ($args === false) {
            return;
        }

        if ($args === true || $args === null) {
            $builder->{$method}();

            return;
        }

        $builder->{$method}($args);
    }
}
