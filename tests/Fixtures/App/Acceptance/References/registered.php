<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Http\Client\RequestException;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\HookLog;

// AT-24 — http-client.md — Throwing Exceptions: "->registered(function (): void {
// RequestException::truncateAt(240); ... })"
return function (Application $app): void {
    HookLog::record('registered.php');

    RequestException::truncateAt(240);
};
