<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Validation;

use ZeroToProd\LaravelDeclaration\DeclaredRequest;

/** condition reference returning bool — evaluated eagerly, once per request, with $request injected. */
final class IsTeamAdmin
{
    public function __invoke(DeclaredRequest $request): bool
    {
        return $request->boolean('admin');
    }
}
