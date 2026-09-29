<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use LogicException;

final class DeclaredQuery
{
    /** @param  array<string, mixed>  $parameters */
    public static function run(string $name, array $parameters = []): mixed
    {
        $Query = app(Manifest::class)->queries->get($name);

        if (! $Query instanceof Query) {
            throw new LogicException("The declared query [$name] does not exist.");
        }

        return $Query->run($parameters);
    }
}
