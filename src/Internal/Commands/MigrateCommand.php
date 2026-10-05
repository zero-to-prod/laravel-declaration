<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use ZeroToProd\LaravelDeclaration\Internal\GuardedBlueprint;
use ZeroToProd\LaravelDeclaration\Internal\ManifestStore;
use ZeroToProd\Manifest\Interpreter;

/**
 * Drives the `schema` data key — a list of invocation nodes on the schema builder — through the interpreter with
 * the guard installed, in manifest order. A `table` node is fed one statement per `table()` call so each blueprint
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

        /** @var array<string, mixed> $node */
        foreach ($schema as $node) {
            if (($node['method'] ?? null) !== 'table') {
                $interpreter->body($builder, [$node]);

                continue;
            }

            /** @var array{0: string, 1: list<array<string, mixed>>} $args  Builder::table($table, Closure $callback) */
            $args = array_values((array) ($node['args'] ?? []));
            [$table, $statements] = $args;

            foreach ($statements as $statement) {
                $interpreter->body($builder, [['method' => 'table', 'args' => [$table, [$statement]]]]);
            }

            if (($tally->altered[$table] ?? 0) > 0) {
                $this->components->twoColumnDetail($table, "<fg=green;options=bold>Altered [{$tally->altered[$table]}] action(s)</>");
            }
        }

        $this->components->info("Schema migration complete. [$tally->created] table(s) created.");

        return self::SUCCESS;
    }
}
