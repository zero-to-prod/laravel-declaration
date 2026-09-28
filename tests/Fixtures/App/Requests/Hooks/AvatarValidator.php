<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Requests\Hooks;

use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Contracts\Validation\Validator;
use ZeroToProd\LaravelDeclaration\DeclaredRequest;

final class AvatarValidator
{
    public function make(DeclaredRequest $request, ValidationFactory $factory): Validator
    {
        return $factory->make($request->all(), ['file' => ['required', 'string']]);
    }
}
