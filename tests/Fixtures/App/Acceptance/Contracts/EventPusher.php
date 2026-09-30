<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Contracts;

/**
 * The interface `app.bind` maps an implementation onto (AT-01) —
 * container.md — Binding Interfaces to Implementations.
 */
interface EventPusher
{
    public function push(string $event): void;
}
