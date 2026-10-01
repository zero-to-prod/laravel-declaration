<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\Doc;

use Illuminate\Support\ServiceProvider;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\Doc\Contracts\DowntimeNotifier;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\Doc\Services\PingdomDowntimeNotifier;

/** The doc's `$singletons` property, alone — providers.md — The `bindings` and `singletons` Properties (AT-04). */
final class SingletonsPropertyProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public $singletons = [
        DowntimeNotifier::class => PingdomDowntimeNotifier::class,
    ];

    public function register(): void {}
}
