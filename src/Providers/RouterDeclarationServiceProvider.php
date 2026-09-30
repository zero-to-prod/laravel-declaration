<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use ZeroToProd\LaravelDeclaration\Attributes\Append;
use ZeroToProd\LaravelDeclaration\Attributes\AppendTo;
use ZeroToProd\LaravelDeclaration\Attributes\Binding;
use ZeroToProd\LaravelDeclaration\Attributes\PrependTo;
use ZeroToProd\LaravelDeclaration\Attributes\Setter;
use ZeroToProd\LaravelDeclaration\Manifest;
use ZeroToProd\LaravelDeclaration\Router as RouterDeclaration;

/** @internal */
class RouterDeclarationServiceProvider extends ServiceProvider
{
    public function boot(?Manifest $Manifest = null, ?Router $Router = null): void
    {
        if (! $Manifest?->router instanceof RouterDeclaration || ! $Router instanceof Router) {
            return;
        }

        foreach (RouterDeclaration::selected(Binding::class) as $method) {
            foreach ($Manifest->router->{$method} as $key => $value) {
                $Router->{$method}($key, $value);
            }
        }

        foreach (RouterDeclaration::selected(Setter::class) as $method) {
            if ($Manifest->router->{$method} !== null) {
                $Router->{$method}($Manifest->router->{$method});
            }
        }

        foreach (RouterDeclaration::selected(PrependTo::class) as $method) {
            foreach ($Manifest->router->{$method} as $group => $middlewares) {
                foreach (array_reverse((array) $middlewares) as $middleware) {
                    $Router->{$method}($group, $middleware);
                }
            }
        }

        foreach (RouterDeclaration::selected(AppendTo::class) as $method) {
            foreach ($Manifest->router->{$method} as $group => $middlewares) {
                foreach ((array) $middlewares as $middleware) {
                    $Router->{$method}($group, $middleware);
                }
            }
        }

        foreach (RouterDeclaration::selected(Append::class) as $method) {
            foreach ($Manifest->router->{$method} as $callback) {
                $Router->{$method}($callback);
            }
        }
    }
}
