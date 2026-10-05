<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\Interpreter;

/** A namespaced function the ρ suites' `closure` vocabulary resolves. */
function interpreter_reference(string $value): string
{
    return "fn:$value";
}
