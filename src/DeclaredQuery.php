<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use LogicException;

final class DeclaredQuery
{
    /** @param  array<string, mixed>  $parameters */
    public static function run(string $name, array $parameters = []): mixed
    {
        $query = app(Manifest::class)->queries->get($name);

        if (! $query instanceof Query) {
            throw new LogicException("The declared query [$name] does not exist.");
        }

        return $query->run($parameters);
    }
}
