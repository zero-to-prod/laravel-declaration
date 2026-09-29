<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Throwable;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models\Post;

/** Resolves without a database: a numeric value is its id, anything else is not found. */
final class User extends Model
{
    protected $guarded = [];

    public function resolveRouteBinding(mixed $value, mixed $field = null): ?self
    {
        if (! is_numeric($value)) {
            return null;
        }

        try {
            return self::query()->find($value) ?? new self(['id' => (int) $value]);
        } catch (Throwable) {
            return new self(['id' => (int) $value]);
        }
    }

    /** @return HasMany<Post, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'user_id');
    }
}
