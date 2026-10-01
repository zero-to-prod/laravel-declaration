<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Controllers;

/** Returns its own output; AT-01 uses its `alternate` action as the second declared action. */
final class EchoController
{
    public function __invoke(): string
    {
        return 'route-output';
    }

    public function alternate(): string
    {
        return 'alternate-route-output';
    }
}
