<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsSubscribed
{
    public function handle(Request $request, Closure $next): Response
    {
        MiddlewareLog::record(self::class);

        if (! $request->hasHeader('X-Subscribed')) {
            return response('Forbidden', 403);
        }

        return $next($request);
    }
}
