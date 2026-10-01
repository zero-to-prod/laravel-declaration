<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\Doc;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\Doc\Services\Riak\Connection;

/** The doc's RiakServiceProvider — providers.md — The Register Method (AT-02). */
final class RiakServiceProvider extends ServiceProvider
{
    /** The doc's closure builds `new Connection(config('riak'))`; the fixture pins the value. */
    public function register(): void
    {
        $this->app->singleton(Connection::class, fn (Application $app): Connection => new Connection(['host' => 'riak.local']));
    }
}
