<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use ZeroToProd\LaravelDeclaration\Internal\GuardedBlueprint;
use ZeroToProd\LaravelDeclaration\Internal\ManifestStore;
use ZeroToProd\Manifest\Interpreter;

/**
 * Drives the `schema` data key through the interpreter onto the schema builder with the guard installed. The
 * fixed lifecycle order — dropIfExists → drop → rename → create → table — is the command's orchestration,
 * expressed as the order it feeds keys to `body()`; a table body is fed one key at a time so each blueprint
 * is pruned against committed state.
 *
 * @internal
 */
class MigrateCommand extends Command
{
    /** @var string */
    protected $signature = 'declaration:migrate {--connection= : The database connection whose schema builder receives the body}';

    /** @var array<int, string> */
    protected $aliases = ['laravel-declaration:migrate'];

    /** @var string */
    protected $description = 'Execute declarative database schema actions declared in manifest';

    public function handle(ManifestStore $store, Interpreter $interpreter): int
    {
        $schema = $store->block('schema');

        if (! is_array($schema)) {
            $this->components->info('No declarative schema defined in manifest.');

            return self::SUCCESS;
        }

        $connection = $this->option('connection');
        $builder = SchemaFacade::connection(is_string($connection) ? $connection : null);

        $tally = GuardedBlueprint::install($builder, fn (string $subject, string $message) => $this->components->twoColumnDetail($subject, $message));

        foreach (['dropIfExists', 'drop', 'rename', 'create'] as $verb) {
            if (array_key_exists($verb, $schema)) {
                $interpreter->body($builder, [$verb => $schema[$verb]]);
            }
        }

        $tables = $schema['table'] ?? [];

        foreach (is_array($tables) ? $tables : [] as $table => $body) {
            $table = (string) $table;

            foreach (is_array($body) ? $body : [] as $method => $value) {
                $interpreter->body($builder, ['table' => [$table => [$method => $value]]]);
            }

            if (($tally->altered[$table] ?? 0) > 0) {
                $this->components->twoColumnDetail($table, "<fg=green;options=bold>Altered [{$tally->altered[$table]}] action(s)</>");
            }
        }

        $this->components->info("Schema migration complete. [$tally->created] table(s) created.");

        return self::SUCCESS;
    }
}
