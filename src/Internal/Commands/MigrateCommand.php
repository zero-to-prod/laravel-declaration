<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use ZeroToProd\LaravelDeclaration\Internal\ActionGuard;
use ZeroToProd\LaravelDeclaration\Manifest;
use ZeroToProd\LaravelDeclaration\Schema;
use ZeroToProd\LaravelDeclaration\TableDefinition;

/** @internal */
class MigrateCommand extends Command
{
    /** @var string */
    protected $signature = 'declaration:migrate';

    /** @var array<int, string> */
    protected $aliases = ['laravel-declaration:migrate'];

    /** @var string */
    protected $description = 'Execute declarative database schema actions declared in manifest';

    public function handle(Manifest $manifest): int
    {
        if (! $manifest->schema instanceof Schema) {
            $this->components->info('No declarative schema defined in manifest.');

            return self::SUCCESS;
        }

        $schema = $manifest->schema;
        $builder = SchemaFacade::connection($schema->connection);

        // Fixed execution order: clear the way, rename targets, create, adjust.
        foreach ($schema->dropIfExists as $table) {
            $builder->dropIfExists($table);
            $this->components->twoColumnDetail($table, '<fg=yellow>Dropped if exists</>');
        }

        foreach ($schema->drop as $table) {
            $builder->drop($table);
            $this->components->twoColumnDetail($table, '<fg=yellow>Dropped</>');
        }

        foreach ($schema->rename as $rename) {
            if (! $builder->hasTable($rename->from) || $builder->hasTable($rename->to)) {
                $this->components->twoColumnDetail($rename->from, '<fg=gray>Rename skipped</>');

                continue;
            }

            $builder->rename($rename->from, $rename->to);
            $this->components->twoColumnDetail($rename->from, "<fg=green;options=bold>Renamed to $rename->to</>");
        }

        $created = 0;

        foreach ($schema->create as $table => $definition) {
            if ($builder->hasTable($table)) {
                $this->components->twoColumnDetail($table, '<fg=gray>Already exists</>');

                continue;
            }

            $builder->create($table, fn (Blueprint $blueprint) => $definition->apply($blueprint));
            $this->components->twoColumnDetail($table, '<fg=green;options=bold>Created</>');
            $created++;
        }

        foreach ($schema->table as $table => $definition) {
            $this->alter($builder, $table, $definition);
        }

        $this->components->info("Schema migration complete. [$created] table(s) created.");

        return self::SUCCESS;
    }

    private function alter(Builder $builder, string $table, TableDefinition $definition): void
    {
        if (! $builder->hasTable($table)) {
            $this->components->twoColumnDetail($table, '<fg=yellow>Table does not exist</>');

            return;
        }

        $altered = 0;

        foreach ($definition->actions as $actions) {
            foreach ($actions as $action) {
                foreach ($action->guards() as $guard) {
                    if (! $this->passes($builder, $table, $guard)) {
                        continue 2;
                    }
                }

                $builder->table($table, fn (Blueprint $blueprint) => $action->apply($blueprint));
                $altered++;
            }
        }

        if ($altered > 0) {
            $this->components->twoColumnDetail($table, "<fg=green;options=bold>Altered [$altered] action(s)</>");
        }
    }

    private function passes(Builder $builder, string $table, ActionGuard $guard): bool
    {
        return $guard->kind->guard()->passes($builder, $table, $guard->target);
    }
}
