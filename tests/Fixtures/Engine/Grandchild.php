<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\Engine;

/** What `Child::constrained()` returns — a chain two returns deep, with void returns that keep the receiver. */
final class Grandchild
{
    use Recording;

    public function cascadeOnDelete(): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function nullable(): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }
}
