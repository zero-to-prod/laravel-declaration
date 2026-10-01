<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\Doc\Services;

use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\Doc\Contracts\ServerProvider;

/** The doc's `App\Services\DigitalOceanServerProvider` — providers.md — The `bindings` and `singletons` Properties. */
final class DigitalOceanServerProvider implements ServerProvider
{
    public function serve(): string
    {
        return 'digitalocean';
    }
}
