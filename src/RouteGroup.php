<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class RouteGroup
{
    use DataModel;

    public const string routes = 'routes';

    public const string prefix = 'prefix';

    public const string as = 'as';

    public const string namespace = 'namespace';

    public const string domain = 'domain';

    public const string controller = 'controller';

    public const string middleware = 'middleware';

    public const string where = 'where';

    public const string metadata = 'metadata';

    #[Describe([Describe::nullable => true])]
    public ?string $prefix;

    #[Describe([Describe::nullable => true])]
    public ?string $as;

    #[Describe([Describe::nullable => true])]
    public ?string $namespace;

    #[Describe([Describe::nullable => true])]
    public ?string $domain;

    #[Describe([Describe::nullable => true])]
    public ?string $controller;

    /** @var string|list<string>|null */
    #[Describe([Describe::nullable => true])]
    public string|array|null $middleware;

    /** @var array<string, string>|null */
    #[Describe([Describe::nullable => true])]
    public ?array $where;

    /** @var array<string, mixed>|null */
    #[Describe([Describe::nullable => true])]
    public ?array $metadata;

    #[Describe([Describe::default => [Routes::class, 'fromRoutes'], Describe::cast => [Routes::class, 'fromRoutes']])]
    public Routes $routes;

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return array_filter([
            self::prefix => $this->prefix,
            self::as => $this->as,
            self::namespace => $this->namespace,
            self::domain => $this->domain,
            self::controller => $this->controller,
            self::middleware => $this->middleware,
            self::where => $this->where,
            self::metadata => $this->metadata,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
