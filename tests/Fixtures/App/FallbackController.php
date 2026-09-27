<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App;

use Illuminate\Http\JsonResponse;

class FallbackController
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['fallback' => true]);
    }
}
