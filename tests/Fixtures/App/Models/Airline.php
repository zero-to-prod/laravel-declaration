<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use ZeroToProd\LaravelDeclaration\DeclaredModel;

/** Declared in `models.yml` with `timestamps: false`. */
final class Airline extends DeclaredModel
{
    /** @return HasMany<Flight, $this> */
    public function flights(): HasMany
    {
        return $this->hasMany(Flight::class);
    }
}
