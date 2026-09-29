<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GlobalFirstMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        MiddlewareLog::record(self::class);

        $response = $next($request);

        $response->headers->set('X-Global-First', '1');

        return $response;
    }
}
