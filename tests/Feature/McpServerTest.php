<?php

declare(strict_types=1);

use Laravel\Mcp\Server\Registrar;
use ZeroToProd\LaravelPackage\Internal\Mcp\Server;
use ZeroToProd\LaravelPackage\Internal\Mcp\Tools\Readme;

it('registers the server under the laravel-package handle', function (): void {
    expect($this->app->make(Registrar::class)->getLocalServer('laravel-package'))->not->toBeNull();
});

it('registers nothing when the server is disabled', function (): void {
    $this->withConfig(['laravel-package.mcp.enabled' => false]);

    expect($this->app->make(Registrar::class)->getLocalServer('laravel-package'))->toBeNull();
});

it('registers under a configured handle', function (): void {
    $this->withConfig(['laravel-package.mcp.handle' => 'package-docs']);

    $registrar = $this->app->make(Registrar::class);

    expect($registrar->getLocalServer('package-docs'))->not->toBeNull()
        ->and($registrar->getLocalServer('laravel-package'))->toBeNull();
});

it('returns the readme', function (): void {
    Server::tool(Readme::class)
        ->assertOk()
        ->assertHasNoErrors()
        ->assertName('readme')
        ->assertSee(file_get_contents(Readme::path()));
});

it('describes the tool so an agent knows when to call it', function (): void {
    $tool = new Readme;

    expect($tool->name())->toBe('readme')
        ->and($tool->description())->toContain('README');
});
