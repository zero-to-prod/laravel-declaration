<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application;

use Illuminate\Support\ServiceProvider;
use Illuminate\View\Factory;

final class ViewWarmProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! config('laravel-declaration.warm-views', false)) {
            return;
        }

        $Factory = app(Factory::class);

        $Factory->addLocation(base_path('resources/declared-views')); // the skeleton's default path is resources/views
        view('greeting')->render();          // resolves `view` now; the finder caches greeting -> declared-views ("base\n")
        $Factory->startSection('banner');    // render bookkeeping for flushState
        $Factory->stopSection();
    }
}
