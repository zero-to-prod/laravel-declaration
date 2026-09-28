<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Requests\Hooks;

use Illuminate\Validation\Validator;

final class ValidateUserStatus
{
    public function __invoke(Validator $validator): void
    {
        if (($validator->getData()['status'] ?? null) === 'banned') {
            $validator->errors()->add('status', 'User is banned.');
        }
    }
}
