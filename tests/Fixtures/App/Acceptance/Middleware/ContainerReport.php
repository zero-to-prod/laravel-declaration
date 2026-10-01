<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware;

/** A container-resolvable dependency of AT-07's `DependencyMiddleware`. */
final class ContainerReport
{
    public function report(): string
    {
        return 'container-resolved';
    }
}
