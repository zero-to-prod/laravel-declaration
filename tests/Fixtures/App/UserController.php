<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App;

use Illuminate\Http\JsonResponse;

class UserController
{
    public function show(): JsonResponse
    {
        return response()->json(['action' => 'show']);
    }

    public function update(): JsonResponse
    {
        return response()->json(['action' => 'update']);
    }

    public function destroy(): JsonResponse
    {
        return response()->json(['action' => 'destroy']);
    }

    public function slow(): JsonResponse
    {
        usleep(260000);

        return response()->json(['action' => 'slow']);
    }
}
