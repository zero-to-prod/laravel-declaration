<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * docs/repos/laravel/docs/middleware.md — Terminable Middleware, the doc's example shape.
 * Each instance records a unique identity so AT-17 can observe the fresh-instance default.
 */
final class TerminatingMiddleware
{
    /** @var list<string> */
    public static array $handled = [];

    /** @var list<string> */
    public static array $terminated = [];

    /** @var list<array{0: class-string, 1: class-string}> */
    public static array $terminateArguments = [];

    private readonly string $instanceId;

    public function __construct()
    {
        $this->instanceId = uniqid('terminating-', true);
    }

    public function handle(Request $request, Closure $next): Response
    {
        self::$handled[] = $this->instanceId;

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        self::$terminated[] = $this->instanceId;
        self::$terminateArguments[] = [$request::class, $response::class];
    }

    public static function reset(): void
    {
        self::$handled = [];
        self::$terminated = [];
        self::$terminateArguments = [];
    }
}
