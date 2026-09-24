<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | MCP Server
    |--------------------------------------------------------------------------
    |
    | The package registers an MCP server so coding agents can read how it is
    | meant to be used. It requires laravel/mcp, and is a no-op without it:
    |
    |     composer require --dev laravel/mcp
    |     php artisan mcp:start laravel-package
    |
    | The `handle` is the name the server is registered under, which is the
    | argument to `mcp:start` and the name your agent refers to it by.
    |
    */

    'mcp' => [
        'enabled' => true,
        'handle' => 'laravel-package',
    ],

];
