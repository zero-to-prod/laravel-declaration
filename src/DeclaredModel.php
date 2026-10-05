<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Database\Eloquent\Model as Eloquent;
use ZeroToProd\LaravelDeclaration\Internal\ManifestStore;

abstract class DeclaredModel extends Eloquent
{
    /** The Eloquent properties a `models` item assigns before Laravel initializes the model. */
    private const array PROPERTIES = [
        'connection', 'table', 'primaryKey', 'keyType', 'incrementing', 'timestamps', 'dateFormat', 'attributes',
        'casts', 'fillable', 'guarded', 'hidden', 'visible', 'appends', 'with', 'withCount', 'touches', 'refreshes',
        'perPage', 'dispatchesEvents', 'observables',
    ];

    /** @param  array<string, mixed>  $attributes */
    public function __construct(array $attributes = [])
    {
        foreach ($this->declared() as $property => $value) {
            $this->{$property} = $value;
        }

        parent::__construct($attributes);
    }

    /**
     * The declared Eloquent properties; every other key keeps the model's own default.
     *
     * @return array<string, mixed>
     */
    private function declared(): array
    {
        return array_filter(
            self::declaration() ?? [],
            static fn (mixed $value, string $property): bool => $value !== null && in_array($property, self::PROPERTIES, true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** @return array<array-key, mixed> */
    public static function resolveObserveAttributes(): array
    {
        return [...parent::resolveObserveAttributes(), ...self::listed('observe')];
    }

    /** @return array<array-key, mixed> */
    public static function resolveGlobalScopeAttributes(): array
    {
        return [...parent::resolveGlobalScopeAttributes(), ...self::listed('addGlobalScope')];
    }

    public function getRouteKeyName(): string
    {
        $key = self::declaration()['getRouteKeyName'] ?? null;

        return is_string($key) ? $key : parent::getRouteKeyName();
    }

    /** @param  string|null  $class */
    public static function isIgnoringTouch($class = null): bool
    {
        return (self::declaration($class)['timestamps'] ?? null) === false || parent::isIgnoringTouch($class);
    }

    /** @return list<mixed> */
    private static function listed(string $key): array
    {
        $value = self::declaration()[$key] ?? [];

        return is_array($value) ? array_values($value) : [];
    }

    /** @return array<string, mixed>|null */
    private static function declaration(?string $class = null): ?array
    {
        return app(ManifestStore::class)->item('models', 'class', $class ?? static::class);
    }
}
