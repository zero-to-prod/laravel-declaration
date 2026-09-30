<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Validation;

use Illuminate\Contracts\Validation\Validator;

final class SlugExtension
{
    /** @param  array<int, string>  $parameters */
    public function check(string $attribute, mixed $value, array $parameters, Validator $validator): bool
    {
        return is_string($value) && preg_match('/^[a-z0-9-]+$/', $value) === 1;
    }
}
