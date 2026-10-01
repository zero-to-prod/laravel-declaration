<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\Doc;

use Illuminate\Support\ServiceProvider;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\Doc\Contracts\ServerProvider;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\Doc\Services\DigitalOceanServerProvider;

/** The doc's `$bindings` property, alone — providers.md — The `bindings` and `singletons` Properties (AT-03). */
final class BindingsPropertyProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        ServerProvider::class => DigitalOceanServerProvider::class,
    ];

    public function register(): void {}
}
