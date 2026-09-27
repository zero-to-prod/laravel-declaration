<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/** Invokable handler for the `missing` builder — the provider wraps it in a Closure. */
class UserMissingHandler
{
    public function __invoke(Request $request, Throwable $e): JsonResponse
    {
        return response()->json(['missing' => true], 404);
    }
}
