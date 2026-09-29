<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Plugins;

use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use ZeroToProd\LaravelDeclaration\Manifest;

class SitemapServiceProvider extends ServiceProvider
{
    public function boot(Manifest $manifest, Router $router): void
    {
        if (! isset($manifest->extra['sitemap'])) {
            return;
        }

        /** @var array{path: string} $sitemap */
        $sitemap = $manifest->extra['sitemap'];

        $router->get($sitemap['path'], static fn (): ResponseFactory|\Illuminate\Http\Response => response('<urlset></urlset>', 200, [
            'Content-Type' => 'application/xml',
        ]));
    }
}
