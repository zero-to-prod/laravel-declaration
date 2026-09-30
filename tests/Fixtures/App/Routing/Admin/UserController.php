<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing\Admin;

class UserController
{
    public function index(): void {}

    public function show(string $user): void {}

    public function store(): void {}

    public function update(string $user): void {}

    public function destroy(string $user): void {}

    public function create(): void {}

    public function edit(string $user): void {}
}
