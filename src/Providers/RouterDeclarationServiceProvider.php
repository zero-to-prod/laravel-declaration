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
    }
}
