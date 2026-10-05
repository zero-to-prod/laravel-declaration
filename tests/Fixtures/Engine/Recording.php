<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\Engine;

/** Every fixture receiver records the calls the engine makes on it. */
trait Recording
{
    /** @var list<array{0: string, 1: array<int|string, mixed>}> */
    public array $calls = [];

    /** @param  array<int|string, mixed>  $arguments */
    protected function record(string $method, array $arguments): void
    {
        $this->calls[] = [$method, $arguments];
    }

    public function fromTrait(string $value): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }
}
