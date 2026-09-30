<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use ZeroToProd\LaravelDeclaration\Database;
use ZeroToProd\LaravelDeclaration\Manifest;

/** @internal */
class DatabaseDeclarationServiceProvider extends ServiceProvider
{
    public function boot(?Manifest $Manifest = null): void
    {
        if (! $Manifest?->db instanceof Database) {
            return;
        }

        $DatabaseManager = $this->app->make(DatabaseManager::class);

        foreach ($Manifest->db->listen as $listener) {
            $DatabaseManager->connection($Manifest->db->connection)->listen(function ($query) use ($listener): void {
                $this->app->call($listener, ['query' => $query]);
            });
        }
    }
}
