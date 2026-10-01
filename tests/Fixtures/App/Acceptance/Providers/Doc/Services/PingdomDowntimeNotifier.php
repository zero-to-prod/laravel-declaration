<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\Doc\Services;

use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\Doc\Contracts\DowntimeNotifier;

/** The doc's `App\Services\PingdomDowntimeNotifier` — providers.md — The `bindings` and `singletons` Properties. */
final class PingdomDowntimeNotifier implements DowntimeNotifier
{
    public function notify(): string
    {
        return 'pingdom';
    }
}
