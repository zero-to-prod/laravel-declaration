<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Attributes;

use Attribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class BelongsTo extends Clause
{
    /**
     * @param  Builder<Model>|Relation<Model, Model, mixed>  $builder
     * @param  array<string, mixed>  $parameters
     */
    public function apply(Builder|Relation $builder, string $method, mixed $args, array $parameters = []): void
    {
        [$param, $relation] = is_array($args) ? [$args[0] ?? '', $args[1] ?? null] : [$args, null];
        $owner = is_string($param) ? ($parameters[$param] ?? null) : null;

        if (! $owner instanceof Model) {
            throw new InvalidArgumentException('Route parameter ['.(is_scalar($param) ? (string) $param : get_debug_type($param)).'] must be an instance of Illuminate\\Database\\Eloquent\\Model.');
        }

        $relation !== null
            ? $builder->whereBelongsTo($owner, is_string($relation) ? $relation : null)
            : $builder->whereBelongsTo($owner);
    }
}
