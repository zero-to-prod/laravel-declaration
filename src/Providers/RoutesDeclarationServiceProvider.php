<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Closure;
use Illuminate\Routing\PendingResourceRegistration;
use Illuminate\Routing\PendingSingletonResourceRegistration;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use LogicException;
use ReflectionMethod;
use UnitEnum;
use ZeroToProd\LaravelDeclaration\Manifest;
use ZeroToProd\LaravelDeclaration\Route as RouteDeclaration;
use ZeroToProd\LaravelDeclaration\RouteGroup;
use ZeroToProd\LaravelDeclaration\RoutePermanentRedirect;
use ZeroToProd\LaravelDeclaration\RouteRedirect;
use ZeroToProd\LaravelDeclaration\RouteResource;
use ZeroToProd\LaravelDeclaration\Routes;
use ZeroToProd\LaravelDeclaration\RouteView;

/** @internal */
class RoutesDeclarationServiceProvider extends ServiceProvider
{
    /** The complete two-argument Pending fluent surface: one call per map entry, key = first argument. */
    private const array TWO_ARGUMENT_PENDING_SETTERS = [
        'name' => true,
        'parameter' => true,
        'middlewareFor' => true,
        'withoutMiddlewareFor' => true,
    ];

    public function boot(?Manifest $Manifest = null, ?Router $Router = null): void
    {
        if (! $Manifest instanceof Manifest || ! $Router instanceof Router) {
            return;
        }

        $this->registerRoutes($Router, $Manifest->routes);
    }

    private function registerRoutes(Router $Router, Routes $Routes): void
    {
        foreach ($Routes->registrars() as $method => $items) {
            foreach ($items as $item) {
                match (true) {
                    $item instanceof RouteGroup => $Router->group(
                        $item->attributes(),
                        fn (Router $Router) => $this->registerRoutes($Router, $item->routes), // Rule 4: Closure where Laravel needs one
                    ),
                    $item instanceof RouteResource => $this->registerResource($Router, $method, $item),
                    default => $this->registerRoute($Router, $method, $item),
                };
            }
        }
    }

    private function registerRoute(Router $Router, string $method, RouteDeclaration|RouteView|RouteRedirect|RoutePermanentRedirect $item): void
    {
        $Route = $Router->{$method}(...$item->arguments());

        assert($Route instanceof Route); // @codeCoverageIgnore

        $this->applyBuilders($Route, $item->builders);
    }

    /** @param  array<string, mixed>  $builders */
    private function applyBuilders(Route $Route, array $builders): void
    {
        foreach ($builders as $method => $arguments) {
            $this->applyBuilder($Route, $method, $arguments);
        }
    }

    private function registerResource(Router $Router, string $method, RouteResource $Resource): void
    {
        $Pending = $Router->{$method}($Resource->name, $Resource->controller);

        foreach ($Resource->options as $option => $arguments) {
            $this->applyPending($Pending, $option, $arguments);
        }
    }

    private function applyPending(
        PendingResourceRegistration|PendingSingletonResourceRegistration $Pending,
        string $method,
        mixed $arguments,
    ): void {
        if ($arguments === false || $arguments === null) {
            return;
        }

        if ($arguments === true) {
            $Pending->{$method}();

            return;
        }

        if ($method === 'missing' && is_string($arguments) && $Pending instanceof PendingResourceRegistration) {
            $Pending->missing($this->wrapMissingHandler($arguments));

            return;
        }

        // Rule 2 map form for the two-argument Pending setters (name, parameter,
        // middlewareFor, withoutMiddlewareFor): one call per entry, key = first argument.
        if (self::TWO_ARGUMENT_PENDING_SETTERS[$method] ?? false) {
            foreach ((array) $arguments as $first => $second) {
                $Pending->{$method}($first, $second);
            }

            return;
        }

        if (is_array($arguments) && array_is_list($arguments)) {
            $spread = method_exists($Pending, $method)
                && new ReflectionMethod($Pending, $method)->getNumberOfParameters() > 1;

            if ($spread) {
                $Pending->{$method}(...$arguments);

                return;
            }

            $Pending->{$method}($arguments);

            return;
        }

        $Pending->{$method}($arguments);
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
