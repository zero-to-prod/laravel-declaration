<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Closure;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use LogicException;
use UnitEnum;
use ZeroToProd\LaravelDeclaration\Manifest;

/** @internal */
class RoutesDeclarationServiceProvider extends ServiceProvider
{
    public function boot(?Manifest $Manifest = null, ?Router $Router = null): void
    {
        if (! $Manifest instanceof Manifest || ! $Router instanceof Router) {
            return;
        }

        foreach ($Manifest->routes as $Routes) {
            if ($Routes->action === null) {
                throw new LogicException("Route for URI [{$Routes->uri}] must specify an action.");
            }

            $Route = $Router->addRoute(
                methods: array_map(strtoupper(...), (array) $Routes->methods),
                uri: $Routes->uri,
                action: $Routes->action
            );

            foreach ($Routes->builders as $method => $arguments) {
                $this->applyBuilder($Route, $method, $arguments);
            }
        }
    }

    private function applyBuilder(Route $Route, string $method, mixed $arguments): void
    {
        if ($arguments === false || $arguments === null) {
            return;
        }

        if ($arguments === true) {
            $Route->{$method}();

            return;
        }

        if ($method === 'missing' && is_string($arguments)) {
            $Route->missing($this->wrapMissingHandler($arguments));

            return;
        }

        if ($method === 'metadata' && is_array($arguments)) {
            $Route->setMetadata($arguments);
            foreach ($arguments as $key => $value) {
                $Route->defaults[$key] = $value;
            }

            return;
        }

        if ($method === 'bindingFields') {
            $Route->setBindingFields((array) $arguments);

            return;
        }

        if ($method === 'can' && is_array($arguments) && isset($arguments['ability'])
            && (is_string($arguments['ability'])
                || $arguments['ability'] instanceof UnitEnum)
        ) {
            $models = isset($arguments['models']) && (is_array($arguments['models']) || is_string($arguments['models'])) ? $arguments['models'] : [];
            $Route->can($arguments['ability'], $models);

            return;
        }

        if ($method === 'block' && is_array($arguments)) {
            $Route->block(
                isset($arguments['lockSeconds']) && is_numeric($arguments['lockSeconds']) ? (int) $arguments['lockSeconds'] : 10,
                isset($arguments['waitSeconds']) && is_numeric($arguments['waitSeconds']) ? (int) $arguments['waitSeconds'] : 10
            );

            return;
        }

        if (is_array($arguments) && array_is_list($arguments)) {
            $Route->{$method}(...$arguments);

            return;
        }

        $Route->{$method}($arguments);
    }

    private function wrapMissingHandler(string $handler): Closure
    {
        return function ($request, $e) use ($handler): mixed {
            $handlerInstance = $this->app->make($handler);

            if (! is_callable($handlerInstance)) {
                throw new LogicException("The `missing` handler [$handler] must be invokable.");
            }

            return $handlerInstance($request, $e);
        };
    }
}
