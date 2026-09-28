<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App;

use Illuminate\Support\ServiceProvider;

class ConfigSpyProvider extends ServiceProvider
{
    /** @var array<string, mixed> */
    public static array $seen = [];

    public function register(): void
    {
        self::$seen['register'] = config('app.name');
    }

    public function boot(): void
    {
        self::$seen['boot'] = config('app.name');
    }
}
