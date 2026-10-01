<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal;

use ZeroToProd\LaravelDeclaration\BlueprintAction;

/** @internal */
abstract class Guards
{
    /** Conventional column targets for zero-argument / void-returning factories and droppers. */
    protected const array CANONICAL_COLUMNS = [
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

    /** @return list<ActionGuard> — all guards must pass before the action dispatches (AND semantics). */
    abstract public function guards(BlueprintAction $action): array;

    protected function argument(BlueprintAction $action, string|int $key): mixed
    {
        if (is_string($key)) {
            $position = BlueprintMethodKind::parameters($action->method)[$key] ?? null;

            return $action->arguments[$key] ?? ($position !== null ? ($action->arguments[$position] ?? null) : null);
        }

        return $action->arguments[$key] ?? null;
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    protected function strings(array $values): array
    {
        $strings = [];

        foreach (array_values($values) as $value) {
            if (is_string($value)) {
                $strings[] = $value;
            }
        }

        return $strings;
    }

    /** @return list<string> every scalar string argument (positional or named), flattened */
    protected function stringArguments(BlueprintAction $action): array
    {
        $flat = [];
        $arguments = $action->arguments;

        array_walk_recursive($arguments, function (mixed $value) use (&$flat): void {
            if (is_string($value)) {
                $flat[] = $value;
            }
        });

        return $flat;
    }

    protected function morphTarget(BlueprintAction $action): ?string
    {
        if ($action->method !== 'morphs' && ! str_ends_with($action->method, 'Morphs')) {
            return null;
        }

        $name = $this->argument($action, 'name');

        return is_string($name) ? "{$name}_id" : null;
    }
}
