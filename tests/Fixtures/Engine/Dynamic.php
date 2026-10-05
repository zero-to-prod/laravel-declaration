<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\Engine;

/** An unprojected `__call` surface (a Fluent): every key dispatches as `(...$arguments)`. */
final class Dynamic
{
    use Recording;

    /** @param  array<int|string, mixed>  $arguments */
    public function __call(string $method, array $arguments): static
    {
        $this->record($method, $arguments);

        return $this;
    }
}
