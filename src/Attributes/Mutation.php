<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Attributes;

use Attribute;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
class Mutation
{
    /**
     * @param  class-string<Model>|object  $target
     * @param  array<array-key, mixed>  $attributes
     */
    public function apply(object|string $target, string $method, array $attributes = [], ?string $column = null): mixed
    {
        if ($method === 'toggle') {
            if (! $target instanceof Model) {
                throw new InvalidArgumentException(
                    'Toggle mutation target must be an instance of '.Model::class.'.'
                );
            }

            if (method_exists($target, 'toggle')) {
                return $target->toggle($column);
            }

            $col = $column ?? 'completed';
            $target->{$col} = ! (bool) $target->{$col};
            $target->save();

            return $target;
        }

        if (is_string($target)) {
            if (! is_subclass_of($target, Model::class)) {
                throw new LogicException("Model class [{$target}] must extend ".Model::class.'.');
            }

            return $target::{$method}($attributes);
        }

        if (! $target instanceof Model) {
            throw new InvalidArgumentException('Mutation target must be an instance of '.Model::class.'.');
        }

        return match ($method) {
            'update' => $target->update($attributes),
            'touch' => $column !== null ? $target->touch($column) : $target->touch(),
            default => $target->{$method}(),
        };
    }
}
