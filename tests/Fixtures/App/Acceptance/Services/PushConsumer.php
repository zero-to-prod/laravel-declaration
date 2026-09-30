<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services;

use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Contracts\EventPusher;

/** A container-resolved class whose constructor type-hints `EventPusher` (AT-01). */
final readonly class PushConsumer
{
    public function __construct(public EventPusher $pusher) {}
}
