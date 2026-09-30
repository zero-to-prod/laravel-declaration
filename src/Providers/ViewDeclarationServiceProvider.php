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
    public function boot(?Manifest $Manifest = null): void
    {
        if (! $Manifest?->view instanceof View) {
            return;
        }

        $this->callAfterResolving('view', function (Factory $factory) use ($Manifest): void {
            foreach ($Manifest->view->addLocation as $location) {
                $factory->addLocation($this->absolute($location));
            }

            foreach ($Manifest->view->prependLocation as $location) {
                $factory->prependLocation($this->absolute($location));
            }

            foreach ($Manifest->view->addNamespace as $namespace => $hints) {
                $factory->addNamespace($namespace, array_map($this->absolute(...), (array) $hints));
            }

            foreach ($Manifest->view->prependNamespace as $namespace => $hints) {
                $factory->prependNamespace($namespace, array_map($this->absolute(...), (array) $hints));
            }

            foreach ($Manifest->view->replaceNamespace as $namespace => $hints) {
                $factory->replaceNamespace($namespace, array_map($this->absolute(...), (array) $hints));
            }

            foreach ($Manifest->view->addExtension as $extension => $engine) {
                $factory->addExtension($extension, $engine);
            }

            if ($Manifest->view->share !== []) {
                $factory->share($Manifest->view->share);
            }

            foreach ($Manifest->view->composer as $views => $callback) {
                $viewsList = str_contains($views, ',')
                    ? array_map(trim(...), explode(',', $views))
                    : $views;

                $factory->composer($viewsList, $callback);
            }

            foreach ($Manifest->view->creator as $views => $callback) {
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
