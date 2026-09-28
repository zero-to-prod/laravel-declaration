<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing;

use Illuminate\Routing\Route;

/** Hints no route parameter, so only an explicit binding can bind one. */
final class ParametersController
{
    /** @return array<string, mixed> */
    public function __invoke(Route $route): array
    {
        return $route->parameters();
    }
}
