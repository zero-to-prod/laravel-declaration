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

    #[Describe([Describe::nullable => true])]
    public ?App $app;

    public const string router = 'router';

    #[Describe([Describe::nullable => true])]
    public ?Router $router;

    public const string view = 'view';

    #[Describe([Describe::nullable => true])]
    public ?View $view;

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

    /** @var Collection<string, Model> */
    #[Describe([
        Describe::cast => [self::class, 'mapOf'],
        'type' => Model::class,
        'key_by' => 'class',
    ])]
    public Collection $models;

    /** @var Collection<int, Route> */
    #[Describe([
        Describe::cast => [self::class, 'mapOf'],
        'type' => Route::class,
    ])]
    public Collection $routes;

    public const string queries = 'queries';

    /** @var Collection<string, Query> */
    #[Describe([
        Describe::cast => [self::class, 'mapOf'],
        'type' => Query::class,
        'key_by' => Query::name,
    ])]
    public Collection $queries;
}
