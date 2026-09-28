<?php

declare(strict_types=1);

// The file IS the hook: its returned Closure is called with the named `app` argument
// plus dependency injection.

use Illuminate\Foundation\Application;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\HookLog;

return static function (Application $app): void {
    HookLog::record('booted.php');
};
