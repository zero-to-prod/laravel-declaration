<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App;

use Illuminate\Http\JsonResponse;
use Throwable;

/** Declares no `__invoke`, so it is not callable as a `missing` handler. */
class NotInvokable
{
    public function handle(Throwable $e): JsonResponse
    {
        return response()->json(['missing' => true], 404);
    }
}
