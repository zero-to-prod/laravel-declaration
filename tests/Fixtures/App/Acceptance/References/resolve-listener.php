<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\Transistor;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\HookLog;

// AT-15 — container.md — Container Events: "the object being resolved will be passed to
// the callback, allowing you to set any additional properties on the object before it is
// given to its consumer."
return function (Transistor $transistor, Application $app): void {
    HookLog::record('Transistor:resolving');

    $transistor->warmed = true;
};
