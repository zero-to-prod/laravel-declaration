<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Support\ServiceProvider;
use ZeroToProd\LaravelDeclaration\Manifest;
use ZeroToProd\LaravelDeclaration\Schema;

/** @internal */
class SchemaDeclarationServiceProvider extends ServiceProvider
{
    public function boot(Manifest $manifest): void
    {
        if (! $manifest->schema instanceof Schema) {
            return;
        }

        if (! Config::boolean('laravel-declaration.schema.auto_migrate', true)) {
            return;
        }

        $schema = $manifest->schema;

        $this->callAfterResolving('db', function () use ($schema): void {
            $this->applySchema($schema);
        });
    }

    public function applySchema(Schema $schema): void
    {
        $schemaBuilder = SchemaFacade::connection($schema->connection);

        foreach ($schema->tables as $tableName => $tableDefinition) {
            if ($schemaBuilder->hasTable($tableName)) {
                continue;
            }

            $schemaBuilder->create($tableName, function (Blueprint $table) use ($tableDefinition): void {
                $tableDefinition->apply($table);
            });
        }
    }
}
