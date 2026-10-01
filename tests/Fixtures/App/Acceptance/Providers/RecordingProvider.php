<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers;

use Illuminate\Support\ServiceProvider;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\HookLog;

/** A provider whose `register()` and `boot()` each record a phase (AT-01) — providers.md — Registering Providers. */
final class RecordingProvider extends ServiceProvider
{
    public function register(): void
    {
        HookLog::record('register');
    }

    public function boot(): void
    {
        HookLog::record('boot');
    }
}
