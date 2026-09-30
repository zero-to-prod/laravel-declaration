<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Closure;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use LogicException;
use ZeroToProd\LaravelDeclaration\App;
use ZeroToProd\LaravelDeclaration\Manifest;

/** @internal */
class AppDeclarationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $Manifest = $this->app->make(Manifest::class);

        if ($Manifest->app === null) {
            return;
        }

        $app = $this->app;
        assert($app instanceof Application);

        $app->registered(fn (Application $appInstance) => $this->registerApplication($Manifest->app, $appInstance));
    }

    private function registerApplication(App $App, Application $app): void
    {
        foreach (['bind', 'bindIf', 'singleton', 'singletonIf', 'scoped', 'scopedIf'] as $method) {
            foreach ($App->{$method} as $abstract => $concrete) {
                if (is_string($abstract)) {
                    $concreteValue = is_string($concrete) ? $this->reference($concrete) : null;
                    $app->{$method}($abstract, $concreteValue);

                    continue;
                }

                $concreteValue = is_string($concrete) ? $this->reference($concrete) : null;

                if ($concreteValue instanceof Closure && in_array($method, ['bindIf', 'singletonIf', 'scopedIf'], true)) {
                    continue;
                }

                $app->{$method}($concreteValue ?? throw new LogicException("The `app.{$method}` list declares a null item; every list item is an abstract."));
            }
        }

        foreach ($App->instance as $abstract => $value) {
            $app->instance($abstract, $this->instantiate($value));
        }

        foreach ($App->alias as $abstract => $alias) {
            $app->alias($abstract, $alias);
        }

        foreach ($App->extend as $abstract => $reference) {
            $app->extend($abstract, $this->wrapExtender($reference));
        }

        foreach ($App->tag as $abstract => $tags) {
            $app->tag($abstract, (array) $tags);
        }

        foreach ($App->when as $concrete => $binding) {
            $give = is_string($binding['give']) ? $this->reference($binding['give']) : $binding['give'];
            if (is_array($give) || $give instanceof Closure || is_string($give)) {
                $app->when($concrete)->needs($binding['needs'])->give($give);
            }
        }

        $paths = [
            'useAppPath',
            'useDatabasePath',
            'useLangPath',
            'usePublicPath',
            'useStoragePath',
            'useBootstrapPath',
            'useConfigPath',
            'useEnvironmentPath',
        ];

        foreach ($paths as $method) {
            if (($path = $App->{$method}) !== null) {
                $app->{$method}($this->absolute($path));
            }
        }

        if ($App->setLocale !== null) {
            $app->setLocale($App->setLocale);
        }

        if ($App->setFallbackLocale !== null) {
            $app->setFallbackLocale($App->setFallbackLocale);
        }

        foreach ($App->registered as $ref) {
            $app->registered($this->wrapCallback($ref));
        }

        foreach ($App->booting as $ref) {
            $app->booting($this->wrapCallback($ref));
        }

        foreach ($App->booted as $ref) {
            $app->booted($this->wrapCallback($ref));
        }

        foreach ($App->resolving as $abstract => $callbacks) {
            foreach ((array) $callbacks as $callback) {
                $app->resolving($abstract, $this->wrapCallback($callback));
            }
        }

        foreach ($App->afterResolving as $abstract => $callbacks) {
            foreach ((array) $callbacks as $callback) {
                $app->afterResolving($abstract, $this->wrapCallback($callback));
            }
        }

        foreach ($App->terminating as $reference) {
            $app->terminating($this->reference($reference));
        }
    }

    private function reference(string $reference): Closure|string
    {
        if (! str_ends_with($reference, '.php')) {
            return $reference;
        }

        $value = $this->fileValue($reference);

        if (! $value instanceof Closure) {
            throw new LogicException("The `app` reference [$reference] must return a Closure, ".get_debug_type($value).' returned.');
        }

        return $value;
    }

    private function instantiate(mixed $value): mixed
    {
        if (is_string($value) && str_ends_with($value, '.php')) {
            return $this->fileValue($value);
        }

        return is_string($value) && (class_exists($value) || interface_exists($value))
            ? $this->app->make($value)
            : $value;
    }

    private function wrapExtender(string $reference): Closure
    {
        return function (mixed $service, Application $app) use ($reference): mixed {
            $callback = $this->reference($reference);

            return $callback instanceof Closure
                ? $callback($service, $app)
                : $app->call($callback, ['service' => $service]);
        };
    }

    private function wrapCallback(string $reference): Closure
    {
        return function (mixed ...$args) use ($reference): void {
            $callback = $this->reference($reference);

            if ($callback instanceof Closure) {
                $callback(...$args);

                return;
            }

            $this->app->call($callback, $args);
        };
    }

    private function fileValue(string $path): mixed
    {
        static $loaded = [];

        $abs = $this->absolute($path);

        return $loaded[$abs] ??= require $abs;
    }

    private function absolute(string $path): string
    {
        return Str::startsWith($path, ['/', '\\']) ? $path : $this->app->basePath($path);
    }
}
