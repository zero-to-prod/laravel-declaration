<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Declared in `router.middlewareGroup.web` of router-middleware.yml; overwritten by the kernel's sync. */
class RouterWeb
{
    public function handle(Request $request, Closure $next): Response
    {
        MiddlewareLog::record(self::class);

        return $next($request);
    }
}
