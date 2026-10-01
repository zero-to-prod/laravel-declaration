<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\Doc\Contracts;

/** The doc's `App\Contracts\ServerProvider` — providers.md — The `bindings` and `singletons` Properties. */
interface ServerProvider
{
    public function serve(): string;
}
