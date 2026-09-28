<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Requests\Hooks;

use Illuminate\Http\Exceptions\HttpResponseException;

final class Unauthorized
{
    public function handle(): never
    {
        throw new HttpResponseException(response()->json(['message' => 'custom denied'], 403));
    }
}
