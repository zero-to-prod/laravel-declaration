<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Validation;

use Illuminate\Validation\Validator;

/** extendImplicit: only implies required — it passes absent and empty values by choice. */
final class OptionalPhone
{
    /** @param  array<int, string>  $parameters */
    public function validate(string $attribute, mixed $value, array $parameters, Validator $validator): bool
    {
        return $value === null || $value === '' || (is_string($value) && preg_match('/^\+?[0-9]{7,15}$/', $value) === 1);
    }
}
