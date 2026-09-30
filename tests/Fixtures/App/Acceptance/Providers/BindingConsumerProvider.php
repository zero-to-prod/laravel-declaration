<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers;

use Illuminate\Support\ServiceProvider;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Contracts\EventPusher;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\HookLog;

/** A provider whose `boot()` resolves a declared binding (AT-26) — providers.md — The Boot Method. */
final class BindingConsumerProvider extends ServiceProvider
{
    public function boot(EventPusher $pusher): void
    {
        HookLog::record('boot:'.get_debug_type($pusher));
    }
}
