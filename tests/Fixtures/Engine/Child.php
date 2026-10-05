<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\Engine;

/** What `Arbitrary::route()` and `Arbitrary::when()` return — the receiver of a chain. */
final class Child
{
    use Recording;

    /** The Grandchild a chain was threaded through. */
    public ?Grandchild $grandchild = null;

    public function name(string $name): static
    {
        $this->record(__FUNCTION__, func_get_args());

        return $this;
    }

    /** @param  mixed  $expression */
    public function where(string $name, $expression = null): static
    {
        $this->record(__FUNCTION__, func_get_args());

        return $this;
    }

    public function constrained(?string $table = null): Grandchild
    {
        $this->record(__FUNCTION__, func_get_args());

        return $this->grandchild = new Grandchild;
    }

    public function done(): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }
}
