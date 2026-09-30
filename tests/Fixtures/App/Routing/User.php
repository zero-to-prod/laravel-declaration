<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing;

use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Throwable;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models\Post;

/** @property int $id */
final class User extends Model implements Authenticatable
{
    use AuthenticatableTrait;

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
