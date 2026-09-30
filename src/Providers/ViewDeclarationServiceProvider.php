<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\View\Factory;
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
            foreach ($view->addLocation as $location) {
                $factory->addLocation($this->absolute($location));
            }

            foreach ($view->prependLocation as $location) {
                $factory->prependLocation($this->absolute($location));
            }

            foreach ($view->addNamespace as $namespace => $hints) {
                $factory->addNamespace($namespace, array_map($this->absolute(...), (array) $hints));
            }

            foreach ($view->prependNamespace as $namespace => $hints) {
                $factory->prependNamespace($namespace, array_map($this->absolute(...), (array) $hints));
            }

            foreach ($view->replaceNamespace as $namespace => $hints) {
                $factory->replaceNamespace($namespace, array_map($this->absolute(...), (array) $hints));
            }

            foreach ($view->addExtension as $extension => $engine) {
                $factory->addExtension($extension, $engine);
            }

            if ($view->share !== []) {
                $factory->share($view->share);
            }

            foreach ($view->composer as $views => $callback) {
                $viewsList = str_contains($views, ',')
                    ? array_map(trim(...), explode(',', $views))
                    : $views;

                $factory->composer($viewsList, $callback);
            }

            foreach ($view->creator as $views => $callback) {
                $viewsList = str_contains($views, ',')
                    ? array_map(trim(...), explode(',', $views))
                    : $views;

                $factory->creator($viewsList, $callback);
            }
        });
    }

    private function absolute(string $path): string
    {
        return Str::startsWith($path, ['/', '\\']) ? $path : $this->app->basePath($path);
    }
}
