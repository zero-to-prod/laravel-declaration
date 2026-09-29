<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Database\Eloquent\Model as Eloquent;

abstract class DeclaredModel extends Eloquent
{
    /** @param  array<string, mixed>  $attributes */
    public function __construct(array $attributes = [])
    {
        foreach (self::declaration()?->properties() ?? [] as $property => $value) {
            $this->{$property} = $value;
        }

        parent::__construct($attributes);
    }

    /** @return array<array-key, mixed> */
    public static function resolveObserveAttributes(): array
    {
        return [...parent::resolveObserveAttributes(), ...self::declaration()->observe ?? []];
    }

    /** @return array<array-key, mixed> */
    public static function resolveGlobalScopeAttributes(): array
    {
        return [...parent::resolveGlobalScopeAttributes(), ...self::declaration()->addGlobalScope ?? []];
    }

    public function getRouteKeyName(): string
    {
        return self::declaration()->getRouteKeyName ?? parent::getRouteKeyName();
    }

    /** @param  string|null  $class */
    public static function isIgnoringTouch($class = null): bool
    {
        return self::declaration($class)?->timestamps === false || parent::isIgnoringTouch($class);
    }

    private static function declaration(?string $class = null): ?Model
    {
        return app(Manifest::class)->models->get($class ?? static::class);
    }
}
