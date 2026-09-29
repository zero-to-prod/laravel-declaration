<?php

declare(strict_types=1);

use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\MiddlewareLog;

return static function ($startedAt, $request, $response): void {
    MiddlewareLog::record('slow-reporter-closure');
};
