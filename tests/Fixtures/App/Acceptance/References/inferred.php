<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\PodcastParser;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\Transistor;

// AT-03 — container.md — Simple Bindings: "you may omit providing the class or interface
// name that you wish to register as a separate argument and instead allow Laravel to
// infer the type from the return type of the closure you provide to the bind method."
return function (Application $app): Transistor {
    $transistor = new Transistor($app->make(PodcastParser::class));

    $transistor->declared = true;

    return $transistor;
};
