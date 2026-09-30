<?php

declare(strict_types=1);

use ZeroToProd\LaravelDeclaration\LaravelDeclarationProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Manifest Path
    |--------------------------------------------------------------------------
    |
    | The file path to the YAML manifest defining your application's
    | declarative infrastructure, routes, schema, and models.
    |
    */
    'manifest' => 'manifest/app.yml',

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Example:
    |   LaravelDeclarationProvider::defaultProviders()
    |       ->replace([RouterDeclarationServiceProvider::class => CustomRouter::class])
    |       ->except([KernelDeclarationServiceProvider::class])
    |       ->toArray(),
    |
    */
    'providers' => LaravelDeclarationProvider::defaultProviders()->toArray(),

    /*
    |--------------------------------------------------------------------------
    | MCP Server
    |--------------------------------------------------------------------------
    |
    | The package registers an MCP server so coding agents can read how it is
    | meant to be used. It requires laravel/mcp, and is a no-op without it:
    |
    |     composer require --dev laravel/mcp
    |     php artisan mcp:start laravel-declaration
    |
    | The `handle` is the name the server is registered under, which is the
    | argument to `mcp:start` and the name your agent refers to it by.
    |
    */
    'mcp' => [
        'enabled' => true,
        'handle' => 'laravel-declaration',
    ],

];
