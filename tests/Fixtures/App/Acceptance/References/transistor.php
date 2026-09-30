<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\PodcastParser;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\Transistor;

// AT-02 — container.md — Simple Bindings: "we receive the container itself as an argument
// to the resolver. We can then use the container to resolve sub-dependencies of the object
// we are building."
return function (Application $app) {
    return new Transistor($app->make(PodcastParser::class));
};
