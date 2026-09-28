<?php

declare(strict_types=1);

// The file IS the factory: its returned Closure is the `$concrete` Laravel's
// build() calls positionally as ($app, $parameters).

use Illuminate\Foundation\Application;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Contracts\Slugger;

return static fn (Application $app, array $parameters = []): Slugger => new class implements Slugger
{
    public function slug(string $value): string
    {
        return str_replace(' ', '-', strtolower($value));
    }
};
