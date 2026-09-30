<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services;

use Illuminate\Contracts\Foundation\Application;

/** A container-resolved consumer of the extended `cache.store` binding (AT-10) — container.md — Extending Bindings. */
final readonly class StoreConsumer
{
    public function __construct(private Application $app) {}

    /** Resolves the extended binding on demand, the way a consumer would. */
    public function store(): mixed
    {
        return $this->app->make('cache.store');
    }
}
