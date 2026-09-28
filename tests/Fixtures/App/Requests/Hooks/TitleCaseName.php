<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Requests\Hooks;

use Illuminate\Support\Str;
use ZeroToProd\LaravelDeclaration\DeclaredRequest;

final class TitleCaseName
{
    public function handle(DeclaredRequest $request): void
    {
        $name = $request->input('name');

        $request->merge(['name' => is_string($name) ? Str::title($name) : $name]);
    }
}
