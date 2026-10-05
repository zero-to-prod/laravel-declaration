<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Manifest Path
    |--------------------------------------------------------------------------
    |
    | The YAML manifest: a body on Illuminate\Foundation\Application. Every
    | root key is one of its methods; timing is written with its lifecycle
    | methods (`make`, `registered`, `booting`, `booted`, `afterResolving`).
    |
    */
    'manifest' => 'manifest/app.yml',

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
