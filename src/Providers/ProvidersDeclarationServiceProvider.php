<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Illuminate\Support\ServiceProvider;
use ZeroToProd\LaravelDeclaration\Manifest;

/** @internal */
class ProvidersDeclarationServiceProvider extends ServiceProvider
{
    public function boot(Manifest $manifest): void
    {
        foreach ($manifest->providers as $provider) {
            $this->app->register($provider->class, $provider->force);
        }
    }
}
