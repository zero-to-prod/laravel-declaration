<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers;

use Illuminate\Support\ServiceProvider;

/** A provider whose `register()` and `boot()` each record a phase (AT-01) — providers.md — Registering Providers. */
final class RecordingProvider extends ServiceProvider
{
    public function register(): void
    {
        PhaseLog::record('register');
    }

    public function boot(): void
    {
        PhaseLog::record('boot');
    }
}
