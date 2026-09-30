<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services;

/** Holds the service and the container the declared extender received (AT-10). */
final readonly class DecoratedStore
{
    public function __construct(public mixed $service, public mixed $container) {}
}
