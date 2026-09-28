<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App;

use Illuminate\Http\JsonResponse;
use ZeroToProd\LaravelDeclaration\DeclaredRequest;

class RequestController
{
    public function store(DeclaredRequest $request): JsonResponse
    {
        $passed = $request->attributes->get('passed', '');

        return response()
            ->json($request->validated())
            ->header('x-passed', is_string($passed) ? $passed : '');
    }

    public function avatar(DeclaredRequest $request): JsonResponse
    {
        return response()->json(['action' => 'avatar']);
    }

    public function hook(DeclaredRequest $request): JsonResponse
    {
        return response()->json(['action' => 'hook']);
    }
}
