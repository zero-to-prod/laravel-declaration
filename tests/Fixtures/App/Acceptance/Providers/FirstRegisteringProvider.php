<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers;

use Illuminate\Support\ServiceProvider;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Contracts\EventPusher;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\RedisEventPusher;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\HookLog;

/** Binds an abstract in `register()` — providers.md — The Boot Method (AT-05). */
final class FirstRegisteringProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EventPusher::class, RedisEventPusher::class);

        HookLog::record('register:first');
    }

    public function boot(): void
    {
        HookLog::record('boot:first');
    }
}
