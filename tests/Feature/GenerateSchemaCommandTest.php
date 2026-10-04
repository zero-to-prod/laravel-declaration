<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use ReflectionException;
use ZeroToProd\LaravelDeclaration\Internal\SchemaGenerator;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\SchemaGenerator\Basic;

it('prints the block key, skipped methods and the fragment', function (): void {
    $this->artisan('declaration:generate-schema', ['class' => Basic::class])
        ->expectsOutputToContain('Block: basic')
        ->expectsOutputToContain('Skipped: version, attach')
        ->expectsOutputToContain('-> label($label) when the key is present')
        ->expectsOutputToContain('TODO(register: $name, $class, $callback)')
        ->assertSuccessful()
        ->run();
});

it('derives the block key from the class basename', function (): void {
    $this->artisan('declaration:generate-schema', ['class' => Router::class])
        ->expectsOutputToContain('Block: router')
        ->assertSuccessful()
        ->run();
});

it('excludes parent methods from the projection (Rule 0.4)', function (): void {
    // Illuminate\Foundation\Application extends Container: bind/singleton declare Container.
    $fragment = SchemaGenerator::render(Application::class);

    expect($fragment['properties'])
        ->not->toHaveKey('bind')
        ->not->toHaveKey('singleton')
        ->toHaveKey('basePath');
});

it('includes trait-provided methods (declaring class is the using class)', function (): void {
    $properties = SchemaGenerator::render(Router::class)['properties'];

    expect($properties)->toHaveKey('macro');
});

it('fails natively for an unknown class (Rule 3.3)', function (): void {
    expect(fn (): array => SchemaGenerator::render('ZeroToProd\Nope'))
        ->toThrow(ReflectionException::class);
});
