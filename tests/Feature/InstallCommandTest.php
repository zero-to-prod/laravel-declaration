<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;

beforeEach(function (): void {
    File::delete(config_path('laravel-package.php'));
});

afterEach(function (): void {
    File::delete(config_path('laravel-package.php'));
});

test('laravel-package:install writes the answers into the configuration', function (): void {
    $this->artisan('laravel-package:install')
        ->expectsConfirmation('Register the MCP server that documents the package to coding agents?', 'yes')
        ->expectsQuestion('The handle the MCP server is registered under', 'package-docs')
        ->expectsOutputToContain('created')
        ->assertSuccessful()
        ->run();

    expect(File::get(config_path('laravel-package.php')))
        ->toContain("'enabled' => true,")
        ->toContain("'handle' => 'package-docs',")
        // The commentary the package ships with survives.
        ->toContain('| MCP Server');
});

test('laravel-package:install turns the MCP server off without asking for a handle', function (): void {
    $this->artisan('laravel-package:install')
        ->expectsConfirmation('Register the MCP server that documents the package to coding agents?', 'no')
        ->expectsOutputToContain('created')
        ->assertSuccessful()
        ->run();

    expect(File::get(config_path('laravel-package.php')))
        ->toContain("'enabled' => false,")
        ->toContain("'handle' => 'laravel-package',");
});

test('laravel-package:install reports what it left alone and asks before overwriting', function (): void {
    $answers = static fn (PendingCommand $command): PendingCommand => $command
        ->expectsConfirmation('Register the MCP server that documents the package to coding agents?', 'yes')
        ->expectsQuestion('The handle the MCP server is registered under', 'laravel-package');

    $answers($this->artisan('laravel-package:install'))->expectsOutputToContain('created')->assertSuccessful()->run();

    // Second run: the file already says what the answers say.
    $answers($this->artisan('laravel-package:install'))->expectsOutputToContain('unchanged')->assertSuccessful()->run();

    File::put(config_path('laravel-package.php'), 'stale');

    $answers($this->artisan('laravel-package:install'))
        ->expectsConfirmation('['.config_path('laravel-package.php').'] differs from these answers. Overwrite it?', 'no')
        ->expectsOutputToContain('kept')
        ->assertSuccessful()
        ->run();

    expect(File::get(config_path('laravel-package.php')))->toBe('stale');

    $answers($this->artisan('laravel-package:install'))
        ->expectsConfirmation('['.config_path('laravel-package.php').'] differs from these answers. Overwrite it?', 'yes')
        ->expectsOutputToContain('updated')
        ->assertSuccessful()
        ->run();

    expect(File::get(config_path('laravel-package.php')))->toContain("'handle' => 'laravel-package',");
});
