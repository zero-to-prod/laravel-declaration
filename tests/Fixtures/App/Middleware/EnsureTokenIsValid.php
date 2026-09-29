<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTokenIsValid
{
    public function handle(Request $request, Closure $next): Response
    {
        MiddlewareLog::record(self::class);

        if ($request->input('token') !== 'valid-token') {
            return response('Unauthorized', 401);
        }

        return $next($request);
    }
}
