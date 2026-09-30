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
    public function boot(Manifest $manifest): void
    {
        if (! $manifest->responses instanceof Response) {
            return;
        }

        $responses = $manifest->responses;
        $factory = $this->app->make(ResponseFactory::class);
        $app = $this->app;

        foreach ($responses->macro as $name => $macroReference) {
            $factory->macro($name, fn (...$args) => $app->call($macroReference, $args));
        }
    }
}
