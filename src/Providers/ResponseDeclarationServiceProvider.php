<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\ServiceProvider;
use ZeroToProd\LaravelDeclaration\Manifest;
use ZeroToProd\LaravelDeclaration\Response;

/** @internal */
class ResponseDeclarationServiceProvider extends ServiceProvider
{
    public function boot(?Manifest $Manifest = null): void
    {
        if (! $Manifest?->responses instanceof Response) {
            return;
        }

        $ResponseFactory = $this->app->make(ResponseFactory::class);
        $Application = $this->app;

        foreach ($Manifest->responses->macro as $name => $macroReference) {
            $ResponseFactory->macro($name, fn (...$args) => $Application->call($macroReference, $args));
        }
    }
}
