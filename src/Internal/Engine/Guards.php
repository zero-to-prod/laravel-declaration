<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Engine;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use ReflectionMethod;

/**
 * The migrate command's guard table (§2.4) — command data, never engine behavior. A table over the receiver and
 * the method-name family; the first matching row decides; the target is read from the call's arguments by
 * parameter name, falling back to the canonical column table.
 *
 * @internal
 */
final class Guards
{
    /** Conventional column targets for zero-argument factories and droppers. */
    public const array CANONICAL_COLUMNS = [
        'id' => 'id',
        'timestamps' => 'created_at',
        'timestampsTz' => 'created_at',
        'nullableTimestamps' => 'created_at',
        'nullableTimestampsTz' => 'created_at',
        'datetimes' => 'created_at',
        'rememberToken' => 'remember_token',
        'softDeletes' => 'deleted_at',
        'softDeletesTz' => 'deleted_at',
        'softDeletesDatetime' => 'deleted_at',
        'dropTimestamps' => 'created_at',
        'dropTimestampsTz' => 'created_at',
        'dropSoftDeletes' => 'deleted_at',
        'dropSoftDeletesTz' => 'deleted_at',
        'dropRememberToken' => 'remember_token',
    ];

    /** Blueprint methods that are not declarable inside a table body. */
    private const array LIFECYCLE = ['build', 'toSql', 'create', 'drop', 'dropIfExists', 'rename', 'after', 'addFluentCommands', 'addAlterCommands', 'macro', 'mixin', 'flushMacros'];

    private const array INDEXES = ['index', 'unique', 'primary', 'fullText', 'spatialIndex', 'vectorIndex', 'rawIndex'];

    private const array DROP_INDEXES = ['dropPrimary', 'dropUnique', 'dropIndex', 'dropFullText', 'dropSpatialIndex', 'dropVectorIndex'];

    public int $created = 0;

    /** @var array<string, int> */
    private array $altered = [];

    /** @var array<string, true> */
    private array $missing = [];

    /** @param  Closure(string, string): void  $report  the two-column console detail */
    public function __construct(
        private readonly Builder $builder,
        private readonly Closure $report,
    ) {}

    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<string, mixed>  $rest
     */
    public function __invoke(object|string $receiver, string $method, array $arguments, array $rest): bool
    {
        if ($receiver instanceof Builder) {
            return $this->builder($method, $this->named($receiver, $method, $arguments));
        }

        if ($receiver instanceof Blueprint) {
            $passes = $this->blueprint($receiver->getTable(), $method, $this->named($receiver, $method, $arguments), $rest);

            if ($passes) {
                $this->altered[$receiver->getTable()] = ($this->altered[$receiver->getTable()] ?? 0) + 1;
            }

            return $passes;
        }

        return true;                                                                    // ColumnDefinition, ForeignKeyDefinition, … — modifiers ride the return
    }

    public function altered(string $table): int
    {
        return $this->altered[$table] ?? 0;
    }

    /** Starts the tally over — the command calls it between the `create` and `table` phases. */
    public function resetAltered(): void
    {
        $this->altered = [];
    }

    /** @param  array<string, mixed>  $arguments */
    private function builder(string $method, array $arguments): bool
    {
        $table = $this->string($arguments, 'table') ?? '';

        switch ($method) {
            case 'create':
                $passes = ! $this->builder->hasTable($table);
                ($this->report)($table, $passes ? '<fg=green;options=bold>Created</>' : '<fg=gray>Already exists</>');
                $this->created += $passes ? 1 : 0;

                return $passes;
            case 'table':
                $passes = $this->builder->hasTable($table);

                if (! $passes && ! isset($this->missing[$table])) {
                    $this->missing[$table] = true;
                    ($this->report)($table, '<fg=yellow>Table does not exist</>');
                }

                return $passes;
            case 'rename':
                $from = $this->string($arguments, 'from') ?? '';
                $to = $this->string($arguments, 'to') ?? '';
                $passes = $this->builder->hasTable($from) && ! $this->builder->hasTable($to);
                ($this->report)($from, $passes ? "<fg=green;options=bold>Renamed to $to</>" : '<fg=gray>Rename skipped</>');

                return $passes;
            case 'drop':
                ($this->report)($table, '<fg=yellow>Dropped</>');

                return true;
            case 'dropIfExists':
                ($this->report)($table, '<fg=yellow>Dropped if exists</>');

                return true;
            default:
                return true;
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $rest
     */
    private function blueprint(string $table, string $method, array $arguments, array $rest): bool
    {
        if (in_array($method, self::LIFECYCLE, true)) {
            return false;                                                               // the schema's own verbs, not a table body's
        }

        if (str_starts_with($method, 'rename')) {                                       // renameColumn / renameIndex
            $from = $this->string($arguments, 'from');
            $to = $this->string($arguments, 'to');

            if ($from === null || $to === null) {
                return true;
            }

            return $method === 'renameIndex'
                ? $this->builder->hasIndex($table, $from) && ! $this->builder->hasIndex($table, $to)
                : $this->builder->hasColumn($table, $from) && ! $this->builder->hasColumn($table, $to);
        }

        if (str_starts_with($method, 'drop')) {
            if (in_array($method, self::DROP_INDEXES, true)) {
                $index = $this->target($arguments, 'index');

                return $index === null || $this->builder->hasIndex($table, $index);
            }

            if ($method === 'dropForeign') {
                $index = $this->target($arguments, 'index');

                return $index === null || $this->builder->hasForeignKey($table, $index);
            }

            $columns = $this->columns($arguments, 'columns', 'column') ?? (isset(self::CANONICAL_COLUMNS[$method]) ? [self::CANONICAL_COLUMNS[$method]] : null);

            return $columns === null || $this->builder->hasColumns($table, $columns);
        }

        if (in_array($method, self::INDEXES, true)) {
            $index = $this->string($arguments, 'name') ?? $this->columns($arguments, 'columns', 'column');

            return $index === null || ! $this->builder->hasIndex($table, $index);
        }

        if ($method === 'foreign') {
            $columns = $this->columns($arguments, 'columns');

            return $columns === null || ! $this->builder->hasForeignKey($table, $columns);
        }

        $column = $this->string($arguments, 'column')
            ?? $this->string($arguments, 'name')
            ?? self::CANONICAL_COLUMNS[$method]
            ?? $this->morph($method, $arguments);

        if ($column === null) {
            return true;
        }

        return array_key_exists('change', $rest)
            ? $this->builder->hasColumn($table, $column)
            : ! $this->builder->hasColumn($table, $column);
    }

    /** @param  array<string, mixed>  $arguments */
    private function morph(string $method, array $arguments): ?string
    {
        if ($method !== 'morphs' && ! str_ends_with($method, 'Morphs')) {
            return null;
        }

        $name = $this->string($arguments, 'name');

        return $name === null ? null : $name.'_id';
    }

    /** @param  array<string, mixed>  $arguments */
    private function string(array $arguments, string $name): ?string
    {
        return is_string($arguments[$name] ?? null) ? $arguments[$name] : null;
    }

    /**
     * A column target as the list the Builder's `has*` probes compare by.
     *
     * @param  array<string, mixed>  $arguments
     * @return list<string>|null
     */
    private function columns(array $arguments, string ...$names): ?array
    {
        $target = $this->target($arguments, ...$names);

        return is_string($target) ? [$target] : $target;
    }

    /**
     * An index or foreign-key target: a name (string) or the columns (list).
     *
     * @param  array<string, mixed>  $arguments
     * @return string|list<string>|null
     */
    private function target(array $arguments, string ...$names): string|array|null
    {
        foreach ($names as $name) {
            $value = $arguments[$name] ?? null;

            if (is_string($value)) {
                return $value;
            }

            if (is_array($value)) {
                return array_values(array_filter($value, is_string(...)));
            }
        }

        return null;
    }

    /**
     * The call's arguments by parameter name: a row is already named; a positional call is paired through the
     * native signature. An unknown method (a `__call` surface) has no parameters to name.
     *
     * @param  array<int|string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function named(object $receiver, string $method, array $arguments): array
    {
        if (! method_exists($receiver, $method)) {
            return [];
        }

        $named = [];

        foreach (new ReflectionMethod($receiver, $method)->getParameters() as $position => $parameter) {
            $name = $parameter->getName();

            if (array_key_exists($name, $arguments)) {
                $named[$name] = $arguments[$name];
            } elseif (array_key_exists($position, $arguments)) {
                $named[$name] = $arguments[$position];
            }
        }

        return $named;
    }
}
