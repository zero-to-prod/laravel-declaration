<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Support\Collection;
use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

readonly class Manifest
{
    use DataModel;

    public const string config = 'config';

    /** @var array<string, mixed> */
    #[Describe([Describe::default => []])]
    public array $config;

    public const string app = 'app';

    /** The `Application` surface; null when the manifest has no (or an empty) `app:` block */
    #[Describe([Describe::nullable => true])]
    public ?App $app;

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
