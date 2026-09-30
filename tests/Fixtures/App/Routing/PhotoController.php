<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing;

class PhotoController
{
    public function index(): void {}

    public function show(string $photo): void {}

    public function store(): void {}

    public function update(string $photo): void {}

    public function destroy(string $photo): void {}
}
