<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Validation;

use Illuminate\Contracts\Validation\Validator;

/** extendImplicit: a bare class-string resolves to the default `validate` method. */
final class Phone
{
    /** @param  array<int, string>  $parameters */
    public function validate(string $attribute, mixed $value, array $parameters, Validator $validator): bool
    {
        return is_string($value) && preg_match('/^\+?[0-9]{7,15}$/', $value) === 1;
    }
}
