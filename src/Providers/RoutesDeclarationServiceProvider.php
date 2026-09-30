<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Closure;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use LogicException;
use ZeroToProd\LaravelDeclaration\Manifest;

/** @internal */
class RoutesDeclarationServiceProvider extends ServiceProvider
{
    public function boot(Manifest $manifest, Router $router): void
    {
        foreach ($manifest->routes as $routeDeclaration) {
            $methods = (array) $routeDeclaration->methods;
            $methods = array_map(strtoupper(...), $methods);

            $action = $routeDeclaration->action;

            if ($action === null) {
                throw new LogicException(
                    "Route for URI [{$routeDeclaration->uri}] must specify an action."
                );
            }

            /** @var LaravelRoute $route */
            $route = $router->addRoute($methods, $routeDeclaration->uri, $action);

            foreach ($routeDeclaration->builders as $method => $arguments) {
                $this->applyBuilder($route, $method, $arguments);
            }
        }
    }

    private function applyBuilder(LaravelRoute $route, string $method, mixed $arguments): void
    {
        if ($arguments === false || $arguments === null) {
            return;
        }

        if ($arguments === true) {
            $route->{$method}();

            return;
        }

        if ($method === 'missing' && is_string($arguments)) {
            $route->missing($this->wrapMissingHandler($arguments));

            return;
        }

        if ($method === 'metadata' && is_array($arguments)) {
            $route->setMetadata($arguments);
            foreach ($arguments as $key => $value) {
                $route->defaults[$key] = $value;
            }

            return;
        }

        if ($method === 'bindingFields') {
            $route->setBindingFields((array) $arguments);

            return;
        }

        if ($method === 'can' && is_array($arguments) && isset($arguments['ability']) && (is_string($arguments['ability']) || $arguments['ability'] instanceof \UnitEnum)) {
            $models = isset($arguments['models']) && (is_array($arguments['models']) || is_string($arguments['models'])) ? $arguments['models'] : [];
            $route->can($arguments['ability'], $models);

            return;
        }

        if ($method === 'block' && is_array($arguments)) {
            $route->block(
                isset($arguments['lockSeconds']) && is_numeric($arguments['lockSeconds']) ? (int) $arguments['lockSeconds'] : 10,
                isset($arguments['waitSeconds']) && is_numeric($arguments['waitSeconds']) ? (int) $arguments['waitSeconds'] : 10
            );

            return;
        }

        if (is_array($arguments) && array_is_list($arguments)) {
            $route->{$method}(...$arguments);

            return;
        }

        $route->{$method}($arguments);
    }

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
