<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Routing\Router;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\AcmeGroupMember;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\KernelAppended;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\RouterWeb;

$manifest = __DIR__.'/../Fixtures/manifest/router-middleware.yml';

it('lets kernel middleware keys win for a registry key both blocks declare', function () use ($manifest): void {
    $this->withConfig([
        'app.key' => 'base64:uAfOGL85H6IA6qwCFx3xTNH5jNOWMUDrVaz/N0TNnhU=',
        'laravel-declaration.manifest' => $manifest,
    ]);

    app(KernelContract::class);

    expect(app(Router::class)->getMiddlewareGroups()['web'])
        ->toContain(KernelAppended::class)
        ->not->toContain(RouterWeb::class);
});

it('keeps router-only groups alive across kernel syncs', function () use ($manifest): void {
    $this->withConfig([
        'app.key' => 'base64:uAfOGL85H6IA6qwCFx3xTNH5jNOWMUDrVaz/N0TNnhU=',
        'laravel-declaration.manifest' => $manifest,
    ]);

    expect(app(Router::class)->getMiddlewareGroups()['acme'])->toBe([AcmeGroupMember::class]);
});
