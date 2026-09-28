<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Requests\Hooks;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use ZeroToProd\LaravelDeclaration\DeclaredRequest;

/** Runs before Laravel's throw; its own exception replaces `ValidationException`. */
final class RespondWithJson
{
    public function handle(DeclaredRequest $request, Validator $validator): never
    {
        throw new HttpResponseException(response()->json(['errors' => $validator->errors()->all()], 422));
    }
}
