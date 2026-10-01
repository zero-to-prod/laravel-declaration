<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\Doc;

use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\Doc\Contracts\ServerProvider;

/** Consumes the contract where it is type-hinted (AT-03) — container.md — Binding Interfaces To Implementations. */
final readonly class ServerProviderConsumer
{
    public function __construct(public ServerProvider $provider) {}
}
