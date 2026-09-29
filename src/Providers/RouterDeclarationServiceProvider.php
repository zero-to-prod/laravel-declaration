<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use ZeroToProd\LaravelDeclaration\Manifest;

/** @internal */
class RouterDeclarationServiceProvider extends ServiceProvider
{
    public function boot(Manifest $manifest, Router $router): void
    {
        if (! $manifest->router instanceof \ZeroToProd\LaravelDeclaration\Router) {
            return;
        }

        foreach ($manifest->router->pattern as $key => $pattern) {
            $router->pattern($key, $pattern);
        }

        foreach ($manifest->router->model as $key => $class) {
            $router->model($key, $class);
        }

        foreach ($manifest->router->bind as $key => $binder) {
            $router->bind($key, $binder);
        }
    }
}
