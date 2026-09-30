<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services;

/** A consumer of `Transistor` — the resolving event fires before it is handed over (AT-15). */
final readonly class TransistorConsumer
{
    public function __construct(public Transistor $transistor) {}
}
