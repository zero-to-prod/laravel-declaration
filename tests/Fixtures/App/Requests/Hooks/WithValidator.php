<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Requests\Hooks;

use Illuminate\Contracts\Validation\Validator;
use ZeroToProd\LaravelDeclaration\DeclaredRequest;

final class WithValidator
{
    public function handle(DeclaredRequest $request, Validator $validator): void
    {
        if ($request->input('file') === 'banned') {
            $validator->after(fn (): mixed => $validator->errors()->add('file', 'File is banned.'));
        }
    }
}
