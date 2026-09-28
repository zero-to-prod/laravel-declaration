<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Support\Collection;
use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class App
{
    use DataModel;

    /** @var Collection<string, Provider> */
    #[Describe([
        Describe::cast => [self::class, 'mapOf'],
        'type' => Provider::class,
        'key_by' => Provider::name,
    ])]
    public Collection $providers;

    /** @var Collection<string, Request> */
    #[Describe([
        Describe::cast => [self::class, 'mapOf'],
        'type' => Request::class,
        'key_by' => Request::name,
    ])]
    public Collection $requests;

    /** @var Collection<int, Route> */
    #[Describe([
        Describe::cast => [self::class, 'mapOf'],
        'type' => Route::class,
    ])]
    public Collection $routes;
}
