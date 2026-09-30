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

    public const string blade = 'blade';

    #[Describe([Describe::nullable => true])]
    public ?Blade $blade;

    public const string responses = 'responses';

    #[Describe([Describe::nullable => true])]
    public ?Response $responses;

    public const string pagination = 'pagination';

    #[Describe([Describe::nullable => true])]
    public ?Pagination $pagination;

    public const string db = 'db';

    #[Describe([Describe::nullable => true])]
    public ?Database $db;

    public const string kernel = 'kernel';

    #[Describe([Describe::nullable => true])]
    public ?Kernel $kernel;

    public const string providers = 'providers';

    /** @var Collection<string, Provider> */
    #[Describe([
        Describe::cast => [self::class, 'mapOf'],
        'type' => Provider::class,
        'key_by' => 'class',
    ])]
    public Collection $providers;

    public const string requests = 'requests';

    /** @var Collection<string, Request> */
    #[Describe([
        Describe::cast => [self::class, 'mapOf'],
        'type' => Request::class,
        'key_by' => Request::name,
    ])]
    public Collection $requests;

    public const string validator = 'validator';

    #[Describe([Describe::nullable => true])]
    public ?Validator $validator;

    public const string models = 'models';

    /** @var Collection<string, Model> */
    #[Describe([
        Describe::cast => [self::class, 'mapOf'],
        'type' => Model::class,
        'key_by' => 'class',
    ])]
    public Collection $models;

    public const string routes = 'routes';

    #[Describe([Describe::default => [Routes::class, 'fromRoutes']])]
    public Routes $routes;

    public const string queries = 'queries';

    /** @var Collection<string, Query> */
    #[Describe([
        Describe::cast => [self::class, 'mapOf'],
        'type' => Query::class,
        'key_by' => Query::name,
    ])]
    public Collection $queries;

    public const string schema = 'schema';

    #[Describe([Describe::nullable => true])]
    public ?Schema $schema;

    public const string extra = 'extra';

    /** @var array<string, mixed> */
    #[Describe([Describe::default => []])]
    public array $extra;
}
