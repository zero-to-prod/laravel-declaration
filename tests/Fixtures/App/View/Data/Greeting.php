<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Data;

use Illuminate\Http\Request;

final class Greeting
{
    public static function for(string $name, Request $request): string
    {
        return "hello $name at {$request->path()}";
    }
}
