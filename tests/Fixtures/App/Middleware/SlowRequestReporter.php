<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware;

class SlowRequestReporter
{
    public function __invoke(mixed $startedAt, mixed $request, mixed $response): void
    {
        MiddlewareLog::record(self::class);
    }
}
