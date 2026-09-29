<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\View\Factory;
use ZeroToProd\LaravelDeclaration\Attributes\Composer;
use ZeroToProd\LaravelDeclaration\Attributes\Location;
use ZeroToProd\LaravelDeclaration\Attributes\ViewNamespace;
use ZeroToProd\LaravelDeclaration\Manifest;
use ZeroToProd\LaravelDeclaration\View;

/** @internal */
class ViewDeclarationServiceProvider extends ServiceProvider
{
    public function boot(Manifest $manifest): void
    {
        if (! $manifest->view instanceof View) {
            return;
        }

        $view = $manifest->view;

        $this->callAfterResolving('view', function (Factory $factory) use ($view): void {
            foreach (View::selected(Location::class) as $method) {
                foreach ($view->{$method} as $location) {
                    $factory->{$method}($this->absolute($location));
                }
            }

            foreach (View::selected(ViewNamespace::class) as $method) {
                foreach ($view->{$method} as $namespace => $hints) {
                    $factory->{$method}($namespace, array_map($this->absolute(...), (array) $hints));
                }
            }

            foreach ($view->addExtension as $extension => $engine) {
                $factory->addExtension($extension, $engine);
            }

            $factory->share($view->share);

            foreach (View::selected(Composer::class) as $method) {
                foreach ($view->{$method} as $callback => $views) {
                    $factory->{$method}($views, $callback);
                }
            }
        });
    }

    private function absolute(string $path): string
    {
        return Str::startsWith($path, ['/', '\\']) ? $path : $this->app->basePath($path);
    }
}
