<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\Engine;

/** A namespaced function the `closure` vocabulary resolves. */
function engine_reference(string $value): string
{
    References::$seen[] = ['function' => $value];

    return "fn:$value";
}
