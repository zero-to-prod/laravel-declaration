<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** docs/repos/laravel/docs/middleware.md — Defining Middleware note: constructor type-hints are injected. */
final readonly class DependencyMiddleware
{
    public function __construct(private ContainerReport $report) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Dependency-Report', $this->report->report());

        return $response;
    }
}
