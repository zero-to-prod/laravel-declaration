<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use ReflectionAttribute;
use ReflectionProperty;
use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class Routes
{
    use DataModel;

    public const string addRoute = 'addRoute';

    public const string group = 'group';

    public const string resource = 'resource';

    public const string apiResource = 'apiResource';

    public const string singleton = 'singleton';

    public const string apiSingleton = 'apiSingleton';

    public const string view = 'view';

    public const string redirect = 'redirect';

    public const string permanentRedirect = 'permanentRedirect';

    /** @var list<Route> */
    #[Describe([Describe::default => [], Describe::cast => [self::class, 'listOf'], 'type' => Route::class])]
    public array $addRoute;

    /** @var list<RouteGroup> */
    #[Describe([Describe::default => [], Describe::cast => [self::class, 'listOf'], 'type' => RouteGroup::class])]
    public array $group;

    /** @var list<RouteResource> */
    #[Describe([Describe::default => [], Describe::cast => [self::class, 'listOf'], 'type' => RouteResource::class])]
    public array $resource;

    /** @var list<RouteResource> */
    #[Describe([Describe::default => [], Describe::cast => [self::class, 'listOf'], 'type' => RouteResource::class])]
    public array $apiResource;

    /** @var list<RouteResource> */
    #[Describe([Describe::default => [], Describe::cast => [self::class, 'listOf'], 'type' => RouteResource::class])]
    public array $singleton;

    /** @var list<RouteResource> */
    #[Describe([Describe::default => [], Describe::cast => [self::class, 'listOf'], 'type' => RouteResource::class])]
    public array $apiSingleton;

    /** @var list<RouteView> */
    #[Describe([Describe::default => [], Describe::cast => [self::class, 'listOf'], 'type' => RouteView::class])]
    public array $view;

    /** @var list<RouteRedirect> */
    #[Describe([Describe::default => [], Describe::cast => [self::class, 'listOf'], 'type' => RouteRedirect::class])]
    public array $redirect;

    /** @var list<RoutePermanentRedirect> */
    #[Describe([Describe::default => [], Describe::cast => [self::class, 'listOf'], 'type' => RoutePermanentRedirect::class])]
    public array $permanentRedirect;

    /** @return array<string, list<Route|RouteGroup|RouteResource|RouteView|RouteRedirect|RoutePermanentRedirect>> */
    public function registrars(): array
    {
        return array_filter([
            self::addRoute => $this->addRoute,
            self::group => $this->group,
            self::resource => $this->resource,
            self::apiResource => $this->apiResource,
            self::singleton => $this->singleton,
            self::apiSingleton => $this->apiSingleton,
            self::view => $this->view,
            self::redirect => $this->redirect,
            self::permanentRedirect => $this->permanentRedirect,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  ReflectionAttribute<Describe>|null  $Attribute
     * @return list<object>
     */
    public static function listOf(mixed $value, array $context, ?ReflectionAttribute $Attribute, ReflectionProperty $Property): array
    {
        $arguments = $Attribute?->getArguments()[0];
        $type = is_array($arguments) ? ($arguments['type'] ?? null) : null;

        if (! is_string($type) || ! is_array($value)) {
            return [];
        }

        /** @var list<array<string, mixed>> $items */
        $items = array_values($value);

        return array_map(static fn (array $item): object => $type::from($item), $items);
    }

    public static function fromRoutes(mixed $value): Routes
    {
        return Routes::from(is_array($value) ? $value : []);
    }
}
