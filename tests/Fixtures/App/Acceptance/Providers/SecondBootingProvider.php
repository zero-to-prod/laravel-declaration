<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers;

use Illuminate\Support\ServiceProvider;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Contracts\EventPusher;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\HookLog;

/** Resolves the first provider's binding in `boot()` — providers.md — The Boot Method (AT-05). */
final class SecondBootingProvider extends ServiceProvider
{
    public function register(): void
    {
        HookLog::record('register:second');
    }

    public function boot(EventPusher $pusher): void
    {
        HookLog::record('boot:second:'.get_debug_type($pusher));
    }
}
