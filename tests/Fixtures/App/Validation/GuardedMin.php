<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Validation;

use Illuminate\Validation\Validator;

/** extendDependent: the parsed rule lands in dependentRules, so parameters may reference other fields. */
final class GuardedMin
{
    /** @param  array<int, string>  $parameters */
    public function check(string $attribute, mixed $value, array $parameters, Validator $validator): bool
    {
        $data = $validator->getData();

        return ! isset($data[$parameters[0]]) || mb_strlen(is_string($value) ? $value : '') >= (int) $parameters[1];
    }
}
