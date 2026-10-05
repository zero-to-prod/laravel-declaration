<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\Engine;

final class Implementation implements Contract
{
    use Recording;

    public function define(string $ability, string $callback): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }
}
