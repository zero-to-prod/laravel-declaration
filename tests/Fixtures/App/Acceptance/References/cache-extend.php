<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\MongoStore;

// AT-25 — cache.md — Registering the Driver: "we will register our custom driver within
// a booting callback."
return function (Application $app): void {
    Cache::extend('mongo', function (Application $app) {
        return Cache::repository(new MongoStore);
    });
};
