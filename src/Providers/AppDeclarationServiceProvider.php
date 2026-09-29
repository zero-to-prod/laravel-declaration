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
        $manifest = $this->app->make(Manifest::class);

        if ($manifest->app === null) {
            return;
        }

        $app = $this->app;
        assert($app instanceof Application);

        $app->registered(fn (Application $appInstance) => $this->registerApplication($manifest->app, $appInstance));
    }

    private function registerApplication(App $App, Application $app): void
    {
        foreach ([App::bind, App::bindIf, App::singleton, App::singletonIf, App::scoped, App::scopedIf] as $method) {
            foreach ($App->{$method} as $abstract => $concrete) {
                is_string($abstract)
                    ? $app->{$method}($abstract, $this->concrete($concrete))
                    : $app->{$method}($this->concrete($concrete) ?? throw new LogicException("The `app.$method` list declares a null item; every list item is an abstract."));
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

        foreach ([App::useAppPath, App::useDatabasePath, App::useLangPath, App::usePublicPath, App::useStoragePath] as $method) {
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

        foreach ($App->registered as $reference) {
            $app->registered($this->wrapCallback($reference));
        }
        foreach ($App->booting as $reference) {
            $app->booting($this->wrapCallback($reference));
        }
        foreach ($App->booted as $reference) {
            $app->booted($this->wrapCallback($reference));
        }
        foreach ($App->terminating as $reference) {
            $app->terminating($this->reference($reference));
        }
    }

    private function concrete(?string $reference): Closure|string|null
    {
        return $reference === null ? null : $this->reference($reference);
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

        return is_string($value) && (class_exists($value) || interface_exists($value)) ? $this->app->make($value) : $value;
    }

    private function wrapExtender(string $reference): Closure
    {
        $value = $this->reference($reference);

        return static fn (mixed $instance, Application $app): mixed => $app->call($value, ['instance' => $instance, 'app' => $app]);
    }

    private function wrapCallback(string $reference): Closure
    {
        $value = $this->reference($reference);

        return static fn (Application $app): mixed => $app->call($value, ['app' => $app]);
    }

    private function absolute(string $path): string
    {
        return Str::startsWith($path, ['/', '\\']) ? $path : $this->app->basePath($path);
    }

    private function fileValue(string $file): mixed
    {
        static $loaded = [];

        $path = $this->absolute($file);

        return $loaded[$path] ??= require $path;
    }
}
