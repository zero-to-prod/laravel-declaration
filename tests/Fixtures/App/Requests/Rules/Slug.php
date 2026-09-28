<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Requests\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A rule class: made with `make()`, so the instance *is* the rule. */
final class Slug implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^[a-z0-9-]+$/', $value)) {
            $fail('The :attribute must be a slug.');
        }
    }
}
