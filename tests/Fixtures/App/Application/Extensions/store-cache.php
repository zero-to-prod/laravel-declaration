<?php

declare(strict_types=1);

// The file IS the extender: $instance and $app are matched by NAME by
// BoundMethod::addDependencyForCallParameter(); anything else is injected by type-hint.

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Application;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\HookLog;

return static function (Repository $instance, Application $app): Repository {
    HookLog::record('store-cache:'.$instance::class);

    $instance->put('app.extension', 'applied');

    return $instance;
};
