<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Support\ServiceProvider;
use ZeroToProd\LaravelDeclaration\Gate;
use ZeroToProd\LaravelDeclaration\Manifest;

/** @internal */
class GateDeclarationServiceProvider extends ServiceProvider
{
    public function boot(?Manifest $Manifest = null): void
    {
        if (! $Manifest?->gate instanceof Gate) {
            return;
        }

        $this->callAfterResolving(GateContract::class, function (GateContract $AccessGate) use ($Manifest): void {
            foreach ($Manifest->gate->policy as $class => $policy) {
                $AccessGate->policy($class, $policy);   // ≙ Gate::policy($class, $policy)
            }

            foreach ($Manifest->gate->define as $ability => $callback) {
                $AccessGate->define($ability, $callback);   // ≙ Gate::define($ability, $callback)
            }
        });
    }
}
