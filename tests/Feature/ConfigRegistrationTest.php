<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Laravel\Mcp\Server\Registrar;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\ConfigSpyProvider;

it('merges the declared values over the file array, YAML winning', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        calls:
          - method: make
            args: {abstract: Illuminate\Config\Repository}
            then: [{method: set, args: [app.name, Tenant Console]}]
          - {method: make, args: {abstract: Illuminate\Config\Repository}, then: [{method: set, args: [app.timezone, UTC]}]}
        YAML)]);

    expect(config('app.name'))->toBe('Tenant Console')
        // every other app.* key from the file survives
        ->and(config('app.env'))->toBe('testing');
});

it('sets per dotted key, replacing nested maps whole', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        calls:
          - {method: make, args: {abstract: Illuminate\Config\Repository}, then: [{method: set, args: [cache.default, redis]}]}
          - method: make
            args: {abstract: Illuminate\Config\Repository}
            then: [{method: set, args: [database.redis, {host: redis-host}]}]
        YAML)]);

    // the `default` key wins, `stores` survives
    expect(config('cache.default'))->toBe('redis')
        ->and(config('cache.stores.array.driver'))->toBe('array')
        // Repository::set semantics: the whole `redis` map is replaced, siblings survive
        ->and(config('database.redis'))->toBe(['host' => 'redis-host'])
        ->and(config('database.migrations'))->not->toBeNull();
});

it('sets a deep dotted key as a config() path, keeping its siblings', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        calls:
          - method: make
            args: {abstract: Illuminate\Config\Repository}
            then: [{method: set, args: [cache.stores.array.serialize, true]}]
        YAML)]);

    expect(config('cache.stores.array.serialize'))->toBeTrue()
        ->and(config('cache.stores.array.driver'))->toBe('array');
});

it('configures this package before its own boot', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        calls:
          - method: make
            args: {abstract: Illuminate\Config\Repository}
            then: [{method: set, args: [laravel-declaration.mcp.enabled, false]}]
        YAML)]);

    expect($this->app->make(Registrar::class)->getLocalServer('laravel-declaration'))->toBeNull();
});

it('gains keys no file declares, with typed YAML values', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        calls:
          - {method: make, args: {abstract: Illuminate\Config\Repository}, then: [{method: set, args: [sentinel.meters, true]}]}
          - {method: make, args: {abstract: Illuminate\Config\Repository}, then: [{method: set, args: [sentinel.limit, 10]}]}
        YAML)]);

    expect(config('sentinel.meters'))->toBeTrue()
        ->and(config('sentinel.limit'))->toBe(10)
        ->and(Config::get('sentinel'))->toBe(['meters' => true, 'limit' => 10]);
});

it('sets a list value through the row form', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        calls:
          - method: make
            args: {abstract: Illuminate\Config\Repository}
            then: [{method: set, args: {key: sentinel.hosts, value: [alpha, beta]}}]
        YAML)]);

    expect(config('sentinel.hosts'))->toBe(['alpha', 'beta']);
});

it('sets nothing when the manifest has no config body', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        calls: [{method: register, args: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\AppServiceProvider]}]
        YAML)]);

    expect(config('app.name'))->toBe('Laravel')
        ->and(Config::get('sentinel'))->toBeNull();
});

it('sets nothing without a manifest', function (): void {
    expect(config('app.name'))->toBe('Laravel')
        ->and(Config::get('sentinel'))->toBeNull();
});

it('declared providers see the set values in register and boot', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        calls:
          - method: make
            args: {abstract: Illuminate\Config\Repository}
            then: [{method: set, args: [app.name, Tenant Console]}]
          - {method: register, args: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\ConfigSpyProvider]}
        YAML)]);

    try {
        expect(ConfigSpyProvider::$seen)->toBe([
            'register' => 'Tenant Console',
            'boot' => 'Tenant Console',
        ]);
    } finally {
        ConfigSpyProvider::$seen = [];
    }
});
