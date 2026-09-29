<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use ZeroToProd\LaravelDeclaration\Attributes\Binding;
use ZeroToProd\LaravelDeclaration\Manifest;
use ZeroToProd\LaravelDeclaration\Router as RouterDeclaration;

/** @internal */
class RouterDeclarationServiceProvider extends ServiceProvider
{
    public function boot(Manifest $manifest, Router $router): void
    {
        if (! $manifest->router instanceof RouterDeclaration) {
            return;
        }

        foreach (RouterDeclaration::selected(Binding::class) as $method) {
            foreach ($manifest->router->{$method} as $key => $value) {
                $router->{$method}($key, $value);
            }
        }
    }
}
