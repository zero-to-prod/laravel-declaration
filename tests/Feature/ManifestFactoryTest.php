<?php

declare(strict_types=1);

use Illuminate\Contracts\Container\BindingResolutionException;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\MockClass;

it('registers the manifest\'s own providers', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/app.yml']);

    expect(app(MockClass::class)->name)->toBe('name');
});

it('does not register a directory', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest']);

    expect(app(MockClass::class));
})->throws(BindingResolutionException::class);
