<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Plugins;

use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use ZeroToProd\LaravelDeclaration\Internal\ManifestStore;

class SitemapServiceProvider extends ServiceProvider
{
    public function boot(ManifestStore $manifest, Router $router): void
    {
        $extra = $manifest->block('extra');

        if (! is_array($extra) || ! isset($extra['sitemap'])) {
            return;
        }

        /** @var array{path: string} $sitemap */
        $sitemap = $extra['sitemap'];

        $router->get($sitemap['path'], static fn (): ResponseFactory|\Illuminate\Http\Response => response('<urlset></urlset>', 200, [
            'Content-Type' => 'application/xml',
        ]));
    }
}
