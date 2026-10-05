<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use ZeroToProd\LaravelDeclaration\Internal\Installer;
use ZeroToProd\LaravelDeclaration\Internal\Mcp\Server;
use ZeroToProd\LaravelDeclaration\Internal\Mcp\Tools\Install;

beforeEach(function (): void {
    File::delete(Installer::path());
    File::delete(Installer::manifestPath());
});

afterEach(function (): void {
    File::delete(Installer::path());
    File::delete(Installer::manifestPath());
    File::deleteDirectory(dirname(Installer::manifestPath()));
});

it('describes the tool so an agent knows when to call it', function (): void {
    $tool = new Install;

    expect($tool->name())->toBe('install')
        ->and($tool->description())->toContain('laravel-declaration:install')
        ->and($tool->toArray()['inputSchema']['properties'])->toHaveKeys(['enabled', 'handle', 'overwrite']);
});

it('writes the configuration an agent asks for', function (): void {
    Server::tool(Install::class, ['enabled' => true, 'handle' => 'package-docs'])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('created');

    expect(File::get(Installer::path()))
        ->toContain("'enabled' => true,")
        ->toContain("'handle' => 'package-docs',")
        // The commentary the package ships with survives.
        ->toContain('| MCP Server');
});

it('falls back to the current configuration for anything the agent omits', function (): void {
    Server::tool(Install::class)->assertOk()->assertSee('created');

    expect(File::get(Installer::path()))
        ->toContain("'enabled' => true,")
        ->toContain("'handle' => 'laravel-declaration',");
});

it('turns the MCP server off', function (): void {
    Server::tool(Install::class, ['enabled' => false])->assertOk()->assertSee('created');

    expect(File::get(Installer::path()))->toContain("'enabled' => false,");
});

it('reports a file that already says the same thing', function (): void {
    Server::tool(Install::class, ['handle' => 'package-docs'])->assertOk()->assertSee('created');

    Server::tool(Install::class, ['handle' => 'package-docs'])
        ->assertOk()
        ->assertSee('already says this');
});

it('keeps a file that says something else until told to overwrite it', function (): void {
    File::put(Installer::path(), 'stale');

    Server::tool(Install::class, ['handle' => 'package-docs'])
        ->assertOk()
        ->assertSee('was left alone');

    expect(File::get(Installer::path()))->toBe('stale');

    Server::tool(Install::class, ['handle' => 'package-docs', 'overwrite' => true])
        ->assertOk()
        ->assertSee('updated');

    expect(File::get(Installer::path()))->toContain("'handle' => 'package-docs',");
});

it('writes the manifest skeleton once and reports an existing manifest as left alone', function (): void {
    Server::tool(Install::class)
        ->assertOk()
        ->assertSee('The manifest skeleton was written to '.Installer::manifestPath());

    expect(File::get(Installer::manifestPath()))->toBe(Installer::manifest());

    File::put(Installer::manifestPath(), "register: []\n");

    Server::tool(Install::class)
        ->assertOk()
        ->assertSee('The manifest at '.Installer::manifestPath().' was left alone.');

    expect(File::get(Installer::manifestPath()))->toBe("register: []\n");
});
