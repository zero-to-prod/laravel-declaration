<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Plugins;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use ZeroToProd\LaravelDeclaration\Internal\ManifestStore;

class CustomRouterServiceProvider extends ServiceProvider
{
    public function boot(ManifestStore $manifest, Router $router): void
    {
        $router->pattern('custom_id', '[0-9]{4}');
    }
}
