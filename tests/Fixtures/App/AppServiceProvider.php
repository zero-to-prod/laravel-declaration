<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->singleton(MockClass::class, fn (): MockClass => new MockClass('name'));
    }
}
