<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Requests\Hooks;

use ZeroToProd\LaravelDeclaration\DeclaredRequest;

final class FlagPassed
{
    public function handle(DeclaredRequest $request): void
    {
        $request->attributes->set('passed', 'yes');
    }
}
