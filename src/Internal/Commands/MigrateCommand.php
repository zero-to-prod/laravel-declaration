<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use ZeroToProd\LaravelDeclaration\Manifest;
use ZeroToProd\LaravelDeclaration\Schema;

/**
 * Idempotently creates missing database tables declared in the manifest.
 *
 * @internal
 */
class MigrateCommand extends Command
{
    /** @var string */
    protected $signature = 'declaration:migrate';

    /** @var array<int, string> */
    protected $aliases = ['laravel-declaration:migrate'];

    /** @var string */
    protected $description = 'Idempotently create missing database tables declared in the manifest';

    public function handle(Manifest $manifest): int
    {
        if (! $manifest->schema instanceof Schema) {
            $this->components->info('No declarative schema defined in manifest.');

            return self::SUCCESS;
        }

        $schema = $manifest->schema;
        $schemaBuilder = SchemaFacade::connection($schema->connection);

        $created = 0;
        foreach ($schema->tables as $tableName => $tableDefinition) {
            if ($schemaBuilder->hasTable($tableName)) {
                $this->components->twoColumnDetail($tableName, '<fg=gray>Already exists</>');

                continue;
            }

            $schemaBuilder->create($tableName, function (Blueprint $table) use ($tableDefinition): void {
                $tableDefinition->apply($table);
            });

            $this->components->twoColumnDetail($tableName, '<fg=green;options=bold>Created</>');
            $created++;
        }

        $this->components->info("Schema migration complete. [{$created}] table(s) created.");

        return self::SUCCESS;
    }
}
