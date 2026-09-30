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
    public function boot(Manifest $manifest): void
    {
        if (! $manifest->db instanceof Database) {
            return;
        }

        $db = $manifest->db;
        $manager = $this->app->make(DatabaseManager::class);

        foreach ($db->listen as $listener) {
            $manager->connection($db->connection)->listen(function ($query) use ($listener): void {
                $this->app->call($listener, ['query' => $query]);
            });
        }
    }
}
