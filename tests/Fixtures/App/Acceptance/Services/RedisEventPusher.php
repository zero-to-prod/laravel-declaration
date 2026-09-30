<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services;

use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Contracts\EventPusher;

/** The implementation `app.bind` declares for `EventPusher` (AT-01). */
final class RedisEventPusher implements EventPusher
{
    public function push(string $event): void {}
}
