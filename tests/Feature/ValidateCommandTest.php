<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

afterEach(function (): void {
    File::delete(storage_path('invalid.yml'));
});

test('laravel-declaration:validate accepts a manifest that matches the schema', function (): void {
    $this->artisan('laravel-declaration:validate', ['--manifest' => __DIR__.'/../Fixtures/manifest/app.yml'])
        ->expectsOutputToContain('is valid')
        ->assertSuccessful();
});

test('laravel-declaration:validate accepts the router block', function (): void {
    $this->artisan('laravel-declaration:validate', ['--manifest' => __DIR__.'/../Fixtures/manifest/router.yml'])
        ->expectsOutputToContain('is valid')
        ->assertSuccessful();
});

test('laravel-declaration:validate accepts the view block', function (): void {
    $this->artisan('laravel-declaration:validate', ['--manifest' => __DIR__.'/../Fixtures/manifest/view.yml'])
        ->expectsOutputToContain('is valid')
        ->assertSuccessful();
});

test('laravel-declaration:validate reports each schema violation', function (): void {
    File::put(storage_path('invalid.yml'), "routes: 5\nbogus: 1\n");

    $this->artisan('laravel-declaration:validate', ['--manifest' => storage_path('invalid.yml')])
        ->expectsOutputToContain('routes: Integer value found, but an array is required.')
        ->expectsOutputToContain('value: The property bogus is not defined')
        ->assertFailed();
});

test('laravel-declaration:validate fails when the manifest is missing', function (): void {
    $this->artisan('laravel-declaration:validate', ['--manifest' => 'missing.yml'])
        ->expectsOutputToContain('Manifest not found')
        ->assertFailed();
});
