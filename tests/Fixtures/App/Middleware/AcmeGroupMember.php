<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Declared in `router.middlewareGroup.acme` of router-middleware.yml; invisible to the kernel's sync. */
class AcmeGroupMember
{
    public function handle(Request $request, Closure $next): Response
    {
        MiddlewareLog::record(self::class);

        return $next($request);
    }
}
