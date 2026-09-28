<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Requests\Hooks;

use ZeroToProd\LaravelDeclaration\DeclaredRequest;

final class ExtraData
{
    /** @return array<string, mixed> */
    public function handle(DeclaredRequest $request): array
    {
        return [...$request->all(), 'extra' => 'manifest'];
    }
}
