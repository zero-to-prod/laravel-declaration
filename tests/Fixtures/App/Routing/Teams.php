<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing;

use Illuminate\Routing\Route;

/** `bind: {team: Teams@bySlug}`: called positionally with ($value, $route). */
final class Teams
{
    public function bySlug(string $value, Route $route): string
    {
        return "$value@{$route->uri()}";
    }
}
