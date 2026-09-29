<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ZeroToProd\LaravelDeclaration\DeclaredModel;

/**
 * @property string $name
 * @property string $code
 * @property string $status
 * @property string|null $secret
 */
final class Flight extends DeclaredModel
{
    /** @return BelongsTo<Airline, $this> */
    public function airline(): BelongsTo
    {
        return $this->belongsTo(Airline::class);
    }

    protected function label(): Attribute
    {
        return Attribute::get(fn (): string => $this->code.' '.$this->name);
    }

    protected function casts(): array
    {
        return ['departed_at' => 'datetime'];
    }

    /**
     * @param  Builder<Flight>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', 'active');
    }
}
