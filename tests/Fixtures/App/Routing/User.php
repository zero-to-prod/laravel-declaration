<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing;

use Illuminate\Database\Eloquent\Model;

/** Resolves without a database: a numeric value is its id, anything else is not found. */
final class User extends Model
{
    protected $guarded = [];

    public function resolveRouteBinding(mixed $value, mixed $field = null): ?self
    {
        return is_numeric($value) ? new self(['id' => (int) $value]) : null;
    }
}
