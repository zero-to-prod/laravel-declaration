<?php

declare(strict_types=1);

use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\LocalDisk;

// AT-12 — container.md — Contextual Binding: "->give(function () { return Storage::disk('local'); })"
return function () {
    return new LocalDisk;
};
