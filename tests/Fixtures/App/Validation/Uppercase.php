<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Validation;

use Illuminate\Contracts\Validation\Validator;

/** extend: `uppercase` — invoked positionally as check($attribute, $value, $parameters, $validator). */
final class Uppercase
{
    /** @param  array<int, string>  $parameters */
    public function check(string $attribute, mixed $value, array $parameters, Validator $validator): bool
    {
        return is_string($value) && ctype_upper($value);
    }

    /** replacer: a bare class-string resolves to `replace` (Str::parseCallback($callback, 'replace')).
     *
     * @param  array<int, string>  $parameters
     */
    public function replace(string $message, string $attribute, string $rule, array $parameters, Validator $validator): string
    {
        return str_replace(':min', $parameters[0] ?? '?', $message);
    }
}
