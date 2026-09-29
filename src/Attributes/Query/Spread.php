<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Attributes\Query;

use Attribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Spread extends Clause
{
    /**
     * @param  Builder<Model>|Relation<Model, Model, mixed>  $builder
     * @param  array<string, mixed>  $parameters
     */
    public function apply(Builder|Relation $builder, string $method, mixed $args, array $parameters = []): void
    {
        $builder->{$method}(...(array) $args);
    }
}
