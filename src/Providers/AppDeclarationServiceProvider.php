<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Closure;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use LogicException;
use ZeroToProd\LaravelDeclaration\App;
use ZeroToProd\LaravelDeclaration\Attributes\Binding;
use ZeroToProd\LaravelDeclaration\Attributes\Conditional;
use ZeroToProd\LaravelDeclaration\Attributes\Path;
use ZeroToProd\LaravelDeclaration\Manifest;

/**
 * @mixin Application
 *
 * @internal
 */
class AppDeclarationServiceProvider extends ServiceProvider
{
    /** @var Application */
    protected $app;

    public function register(): void
    {
        $this->callAfterResolving(Manifest::class, function (Manifest $Manifest, Application $Application): void {
            if (! $Manifest->app instanceof App) {
                return;
            }

            $Application->registered(fn (Application $Application) => $this->registerApplication($Manifest->app, $Application));
        });
    }

    private function registerApplication(App $App, Application $Application): void
    {
        foreach (App::selected(Binding::class) as $method) {
            foreach ($App->{$method} as $abstract => $concrete) {
                $concreteValue = is_string($concrete) ? $this->reference($concrete) : null;
                if (is_string($abstract)) {
                    $Application->{$method}($abstract, $concreteValue);

                    continue;
                }

                if ($concreteValue instanceof Closure && in_array($method, App::selected(Conditional::class), true)) {
                    continue;
                }

                $Application->{$method}(
                    $concreteValue ?? throw new LogicException("The `app.{$method}` list declares a null item; every list item is an abstract.")
                );
            }
        }

        foreach ($App->instance as $abstract => $value) {
            $Application->instance($abstract, $this->instantiate($value));
        }

        foreach ($App->alias as $abstract => $alias) {
            $Application->alias($abstract, $alias);
        }

        foreach ($App->extend as $abstract => $reference) {
            $Application->extend($abstract, $this->wrapExtender($reference));
        }

        foreach ($App->tag as $abstract => $tags) {
            $Application->tag($abstract, (array) $tags);
        }

        foreach ($App->when as $concrete => $binding) {
            $give = is_string($binding[App::give]) ? $this->reference($binding[App::give]) : $binding[App::give];
            if (is_array($give) || $give instanceof Closure || is_string($give)) {
                $Application->when($concrete)->needs($binding[App::needs])->give($give);
            }
        }

        foreach (App::selected(Path::class) as $method) {
            if (($path = $App->{$method}) !== null) {
                $Application->{$method}($this->absolute($path));
            }
        }

        if ($App->setLocale !== null) {
            $Application->setLocale($App->setLocale);
        }

        if ($App->setFallbackLocale !== null) {
            $Application->setFallbackLocale($App->setFallbackLocale);
        }

        foreach ($App->registered as $ref) {
            $Application->registered($this->wrapCallback($ref));
        }

        foreach ($App->booting as $ref) {
            $Application->booting($this->wrapCallback($ref));
        }

        foreach ($App->booted as $ref) {
            $Application->booted($this->wrapCallback($ref));
        }

        foreach ($App->resolving as $abstract => $callbacks) {
            foreach ((array) $callbacks as $callback) {
                $Application->resolving($abstract, $this->wrapCallback($callback));
            }
        }

        foreach ($App->afterResolving as $abstract => $callbacks) {
            foreach ((array) $callbacks as $callback) {
                $Application->afterResolving($abstract, $this->wrapCallback($callback));
            }
        }

        foreach ($App->terminating as $reference) {
            $Application->terminating($this->reference($reference));
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
        return function (mixed $service, Application $Application) use ($reference): mixed {
            $Closure = $this->reference($reference);

            return $Closure instanceof Closure
                ? $Closure($service, $Application)
                : $Application->call($Closure, ['service' => $service]);
        };
    }

    private function wrapCallback(string $reference): Closure
    {
        return function (mixed ...$arguments) use ($reference): void {
            $args = array_combine(array_map(strval(...), array_keys($arguments)), $arguments);
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
