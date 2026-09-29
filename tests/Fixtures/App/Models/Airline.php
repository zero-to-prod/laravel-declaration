<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use ZeroToProd\LaravelDeclaration\DeclaredModel;

final class Airline extends DeclaredModel
{
    /** @return HasMany<Flight, $this> */
    public function flights(): HasMany
    {
        return $this->hasMany(Flight::class);
    }

    public function notARelation(): string
    {
        return 'not a relation';
    }
}
