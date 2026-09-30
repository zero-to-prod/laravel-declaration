<?php

declare(strict_types=1);

use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\DecoratedStore;

// AT-10 — container.md — Extending Bindings: "the closure receives the service being
// resolved and the container instance."
return function ($service, $app) {
    return new DecoratedStore($service, $app);
};
