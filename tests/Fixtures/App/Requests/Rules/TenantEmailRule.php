<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Requests\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use ZeroToProd\LaravelDeclaration\DeclaredRequest;

/** A special rule, resolved per request by `forRequest()` or a function. */
final class TenantEmailRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === 'taken@example.com') {
            $fail('The :attribute has already been taken.');
        }
    }

    public static function forRequest(DeclaredRequest $request): self
    {
        return new self;
    }
}
