<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Closure;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use LogicException;
use ZeroToProd\LaravelDeclaration\Manifest;
use ZeroToProd\LaravelDeclaration\Route;

/** @internal */
class RoutesDeclarationServiceProvider extends ServiceProvider
{
    public function boot(Manifest $manifest, Router $router): void
    {
        foreach ($manifest->routes as $Route) {
            $route = $router->addRoute(
                strtoupper($Route->methods),
                $Route->path,
                $Route->action,
            );

            foreach ($Route->builders() as $method => $value) {
                match (true) {
                    is_bool($value) => $value ? $route->{$method}() : null,
                    $method === Route::missing => $route->missing($this->wrapMissingHandler($Route->missingHandler())),
                    $method === Route::can => $route->can(...$Route->can),
                    $method === Route::block => $route->block(...($Route->block ?? [])),
                    default => $route->{$method}($value),
                };
            }
        }
    }

    /** @param class-string $handler */
    private function wrapMissingHandler(string $handler): Closure
    {
        return function ($request, $e) use ($handler): mixed {
            $handlerInstance = $this->app->make($handler);

            if (! is_callable($handlerInstance)) {
                throw new LogicException("The `missing` handler [{$handler}] must be invokable.");
            }

            return $handlerInstance($request, $e);
        };
    }
}
