<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Fluent;

/**
 * The migrate command's idempotence as the receiver PHP already hands the body. Installed through
 * `Builder::blueprintResolver()`, so the interpreter dispatches onto the real Builder and Blueprint signatures and
 * nothing here reads the manifest: table verbs are decided whole in `build()`, an alter body is pruned column by
 * column and command by command against the live schema just before its SQL runs.
 *
 * @internal
 */
final class GuardedBlueprint extends Blueprint
{
    private const array INDEXES = ['index', 'unique', 'primary', 'fullText', 'spatialIndex', 'vectorIndex', 'rawIndex'];

    /** Installs the guard on a builder; the tally is the command's report. */
    public static function install(Builder $builder, Closure $report): Tally
    {
        $tally = new Tally($report);

        $builder->blueprintResolver(static fn (Connection $connection, string $table, ?Closure $callback = null): Blueprint => new self($builder, $tally, $connection, $table, $callback));

        return $tally;
    }

    private function __construct(
        private readonly Builder $builder,
        private readonly Tally $tally,
        Connection $connection,
        string $table,
        ?Closure $callback = null,
    ) {
        parent::__construct($connection, $table, $callback);                                      // table(): the body runs here, before build()
    }

    public function build(): void
    {
        $table = $this->getTable();

        if ($this->creating()) {
            $exists = $this->builder->hasTable($table);
            ($this->tally->report)($table, $exists ? '<fg=gray>Already exists</>' : '<fg=green;options=bold>Created</>');

            if (! $exists) {
                $this->tally->created++;
                parent::build();
            }

            return;
        }

        $verb = array_find($this->commands, static fn (Fluent $command): bool => ! $command instanceof ColumnDefinition && in_array(self::attr($command, 'name'), ['drop', 'dropIfExists', 'rename'], true));

        if ($verb instanceof Fluent) {
            if ($this->table($table, $verb)) {
                parent::build();
            }

            return;
        }

        if (! $this->builder->hasTable($table)) {
            if (! isset($this->tally->missing[$table])) {
                $this->tally->missing[$table] = true;
                ($this->tally->report)($table, '<fg=yellow>Table does not exist</>');
            }

            return;
        }

        $kept = array_values(array_filter($this->commands, fn (Fluent $command): bool => $command instanceof ColumnDefinition ? $this->column($command) : $this->command($command)));

        $this->commands = $kept;
        $this->columns = array_values(array_filter($this->columns, static fn (ColumnDefinition $column): bool => in_array($column, $kept, true)));
        $this->tally->altered[$table] = ($this->tally->altered[$table] ?? 0) + count($kept);

        if ($kept !== []) {
            parent::build();
        }
    }

    /** A table-level verb: `rename` only when it can succeed; drops always, reported. */
    /** @param  Fluent<string, mixed>  $verb */
    private function table(string $table, Fluent $verb): bool
    {
        switch (self::attr($verb, 'name')) {
            case 'rename':
                $to = self::attr($verb, 'to');
                $passes = $this->builder->hasTable($table) && ! $this->builder->hasTable($to);
                ($this->tally->report)($table, $passes ? "<fg=green;options=bold>Renamed to $to</>" : '<fg=gray>Rename skipped</>');

                return $passes;
            case 'drop':
                ($this->tally->report)($table, '<fg=yellow>Dropped</>');

                return true;
            default:
                ($this->tally->report)($table, '<fg=yellow>Dropped if exists</>');

                return true;
        }
    }

    /** An added column is kept when absent; a `->change()` column when present. */
    private function column(ColumnDefinition $column): bool
    {
        $present = $this->builder->hasColumn($this->getTable(), self::attr($column, 'name'));

        return $column->get('change') === true ? $present : ! $present;
    }

    /** A command is kept when the live schema says it would succeed. Blueprint has already resolved index names. */
    /** @param  Fluent<string, mixed>  $command */
    private function command(Fluent $command): bool
    {
        $table = $this->getTable();
        $name = self::attr($command, 'name');

        return match (true) {
            $name === 'dropColumn' => $this->builder->hasColumns($table, $this->strings($command, 'columns')),
            $name === 'renameColumn' => $this->builder->hasColumn($table, self::attr($command, 'from')) && ! $this->builder->hasColumn($table, self::attr($command, 'to')),
            $name === 'renameIndex' => $this->builder->hasIndex($table, self::attr($command, 'from')) && ! $this->builder->hasIndex($table, self::attr($command, 'to')),
            $name === 'dropForeign' => $this->builder->hasForeignKey($table, $this->target($command, 'index')),
            str_starts_with($name, 'drop') => $this->builder->hasIndex($table, $this->target($command, 'index')),      // dropIndex, dropUnique, dropPrimary, dropFullText, dropSpatialIndex, dropVectorIndex
            $name === 'foreign' => ! $this->builder->hasForeignKey($table, $this->target($command, 'columns')),
            in_array($name, self::INDEXES, true) => ! $this->builder->hasIndex($table, $this->target($command, 'index')),
            default => true,                                                                     // engine, charset, collation, comment, …
        };
    }

    /** The command attribute as a string: `''` when absent or of another type.
     *
     * @param  Fluent<string, mixed>  $command
     */
    private static function attr(Fluent $command, string $key): string
    {
        $value = $command->get($key);

        return is_string($value) ? $value : '';
    }

    /** The command's attribute where the native signature takes the name or the whole list of columns.
     *
     * @param  Fluent<string, mixed>  $command
     * @return list<string>|string
     */
    private function target(Fluent $command, string $key): array|string
    {
        $value = $command->get($key);

        if (is_array($value)) {
            return array_map(static fn (mixed $column): string => is_string($column) ? $column : '', array_values($value));
        }

        return is_string($value) ? $value : '';
    }

    /** The command's `columns` attribute, each entry as a string.
     *
     * @param  Fluent<string, mixed>  $command
     * @return list<string>
     */
    private function strings(Fluent $command, string $key): array
    {
        $value = $command->get($key);
        $columns = is_array($value) ? $value : [];

        return array_map(static fn (mixed $column): string => is_string($column) ? $column : '', array_values($columns));
    }
}
