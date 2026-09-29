<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** @implements Scope<Flight> */
final class NotCancelled implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where('status', '!=', 'cancelled');
    }
}
