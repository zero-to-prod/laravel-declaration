<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Requests;

use ZeroToProd\LaravelDeclaration\DeclaredRequest;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Requests\Rules\TenantEmailRule;

function declared_tenant_email(DeclaredRequest $request): TenantEmailRule
{
    return TenantEmailRule::forRequest($request);
}

function declared_prepare(DeclaredRequest $request): void
{
    $name = $request->input('name');

    $request->merge(['name' => is_string($name) ? trim($name) : null]);
}
