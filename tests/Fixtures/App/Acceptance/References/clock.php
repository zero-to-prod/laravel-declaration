<?php

declare(strict_types=1);

use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services\Clock;

// AT-09 — container.md — Binding Instances: "you may also bind an existing object instance into
// the container using the instance method." The file IS the instance: its return value is bound as-is.
return new Clock;
