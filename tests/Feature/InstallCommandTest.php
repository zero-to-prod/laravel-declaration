<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use ZeroToProd\LaravelDeclaration\Internal\Installer;

beforeEach(function (): void {
    File::delete(config_path('laravel-declaration.php'));
    File::delete(Installer::manifestPath());
});

afterEach(function (): void {
    File::delete(config_path('laravel-declaration.php'));
    File::delete(Installer::manifestPath());
    File::deleteDirectory(dirname(Installer::manifestPath()));
});

test('laravel-declaration:install writes the answers into the configuration', function (): void {
    $this->artisan('laravel-declaration:install')
        ->expectsConfirmation('Register the MCP server that documents the package to coding agents?', 'yes')
        ->expectsQuestion('The handle the MCP server is registered under', 'package-docs')
        ->expectsOutputToContain('created')
        ->assertSuccessful()
        ->run();

    expect(File::get(config_path('laravel-declaration.php')))
        ->toContain("'enabled' => true,")
        ->toContain("'handle' => 'package-docs',")
        // The commentary the package ships with survives.
        ->toContain('| MCP Server');
});

test('laravel-declaration:install turns the MCP server off without asking for a handle', function (): void {
    $this->artisan('laravel-declaration:install')
        ->expectsConfirmation('Register the MCP server that documents the package to coding agents?', 'no')
        ->expectsOutputToContain('created')
        ->assertSuccessful()
        ->run();

    expect(File::get(config_path('laravel-declaration.php')))
        ->toContain("'enabled' => false,")
        ->toContain("'handle' => 'laravel-declaration',");
});

test('laravel-declaration:install reports what it left alone and asks before overwriting', function (): void {
    $answers = static fn (PendingCommand $command): PendingCommand => $command
        ->expectsConfirmation('Register the MCP server that documents the package to coding agents?', 'yes')
        ->expectsQuestion('The handle the MCP server is registered under', 'laravel-declaration');

    $answers($this->artisan('laravel-declaration:install'))->expectsOutputToContain('created')->assertSuccessful()->run();

    // Second run: the file already says what the answers say.
    $answers($this->artisan('laravel-declaration:install'))->expectsOutputToContain('unchanged')->assertSuccessful()->run();

    File::put(config_path('laravel-declaration.php'), 'stale');

    $answers($this->artisan('laravel-declaration:install'))
        ->expectsConfirmation('['.config_path('laravel-declaration.php').'] differs from these answers. Overwrite it?', 'no')
        ->expectsOutputToContain('kept')
        ->assertSuccessful()
        ->run();

    expect(File::get(config_path('laravel-declaration.php')))->toBe('stale');

    $answers($this->artisan('laravel-declaration:install'))
        ->expectsConfirmation('['.config_path('laravel-declaration.php').'] differs from these answers. Overwrite it?', 'yes')
        ->expectsOutputToContain('updated')
        ->assertSuccessful()
        ->run();

    expect(File::get(config_path('laravel-declaration.php')))->toContain("'handle' => 'laravel-declaration',");
});

test('laravel-declaration:install writes the manifest skeleton once and never overwrites it', function (): void {
    $answers = static fn (PendingCommand $command): PendingCommand => $command
        ->expectsConfirmation('Register the MCP server that documents the package to coding agents?', 'no');

    $answers($this->artisan('laravel-declaration:install'))
        ->expectsOutputToContain('app.yml')
        ->expectsOutputToContain('created')
        ->assertSuccessful()
        ->run();

    expect(Installer::manifestPath())->toBe(base_path('manifest/app.yml'))
        ->and(File::get(Installer::manifestPath()))
        ->toBe(Installer::manifest())
        ->toContain('afterResolving:')
        ->toContain(Router::class);

    File::put(Installer::manifestPath(), "register: []\n");

    $answers($this->artisan('laravel-declaration:install'))
        ->expectsOutputToContain('kept')
        ->assertSuccessful()
        ->run();

    expect(File::get(Installer::manifestPath()))->toBe("register: []\n");
});
