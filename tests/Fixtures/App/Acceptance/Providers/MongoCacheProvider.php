<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers;

use Illuminate\Cache\CacheManager;
use Illuminate\Support\ServiceProvider;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\HookLog;

/** A provider whose `boot()` uses the driver the booting callback registered (AT-25). */
final class MongoCacheProvider extends ServiceProvider
{
    public function boot(CacheManager $cache): void
    {
        HookLog::record('mongo-driver:'.get_debug_type($cache->store('mongo')->getStore()));
    }
}
