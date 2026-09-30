<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Illuminate\Support\ServiceProvider;
use ZeroToProd\LaravelDeclaration\Manifest;

/** @internal */
class ProvidersDeclarationServiceProvider extends ServiceProvider
{
    public function boot(?Manifest $Manifest = null): void
    {
        if (! $Manifest instanceof Manifest) {
            return;
        }

        foreach ($Manifest->providers as $Provider) {
            $this->app->register($Provider->class, $Provider->force);
        }
    }
}
