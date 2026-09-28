<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Requests\Hooks;

final class CustomAttributes
{
    /** @return array<string, string> */
    public function handle(): array
    {
        return ['name' => 'custom name'];
    }
}
