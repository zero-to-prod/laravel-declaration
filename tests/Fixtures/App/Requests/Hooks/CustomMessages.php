<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Requests\Hooks;

final class CustomMessages
{
    /** @return array<string, string> */
    public function handle(): array
    {
        return ['name.required' => ':attribute is custom-required.'];
    }
}
