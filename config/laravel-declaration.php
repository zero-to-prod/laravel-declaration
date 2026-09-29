<?php

declare(strict_types=1);

use ZeroToProd\LaravelDeclaration\Providers\AppDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Providers\ConfigDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Providers\KernelDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Providers\ProvidersDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Providers\RouterDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Providers\RoutesDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Providers\SchemaDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Providers\ViewDeclarationServiceProvider;

return [

    'manifest' => 'manifest/app.yml',

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Each declaration is handled by a plain ServiceProvider.
    | To disable, remove it from this list.
    | To replace, substitute your custom ServiceProvider.
    |
    */
    'providers' => [
        ConfigDeclarationServiceProvider::class,
        AppDeclarationServiceProvider::class,
        RouterDeclarationServiceProvider::class,
        ViewDeclarationServiceProvider::class,
        KernelDeclarationServiceProvider::class,
        ProvidersDeclarationServiceProvider::class,
        RoutesDeclarationServiceProvider::class,
        SchemaDeclarationServiceProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema
    |--------------------------------------------------------------------------
    |
    | Missing tables declared in the manifest are created automatically during
    | boot by default. Set auto_migrate to false to disable this and create
    | tables solely via `php artisan declaration:migrate`.
    |
    */
    'schema' => [
        'auto_migrate' => true,
    ],

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
