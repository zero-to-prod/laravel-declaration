<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use BadMethodCallException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use LogicException;
use ZeroToProd\LaravelDeclaration\Internal\ManifestStore;

final class DeclaredQuery
{
    private const string name = 'name';

    private const string model = 'model';

    private const string relation = 'relation';

    /** @param  array<string, mixed>  $parameters */
    public static function run(string $name, array $parameters = []): mixed
    {
        $declaration = app(ManifestStore::class)->item('queries', self::name, $name)
            ?? throw new LogicException("The declared query [{$name}] does not exist.");

        return self::execute($declaration, $parameters);
    }

    /**
     * @param  array<string, mixed>  $declaration  `name`, `model` or `relation`, then the Builder clauses in order
     * @param  array<string, mixed>  $parameters
     *
     * @throws BadMethodCallException
     */
    public static function execute(array $declaration, array $parameters = []): mixed
    {
        $target = self::resolveRoot($declaration, $parameters);
        $result = $target;

        foreach (array_diff_key($declaration, [self::name => true, self::model => true, self::relation => true]) as $method => $args) {
            $result = self::dispatchMethod($target, (string) $method, $args);

            if ($result instanceof Builder || $result instanceof Relation) {
                $target = $result;
            }
        }

        if ($result instanceof Builder || $result instanceof Relation) {
            return $result->get();
        }

        return $result;
    }

    /** @param  Builder<Model>|Relation<Model, Model, mixed>  $target */
    private static function dispatchMethod(Builder|Relation $target, string $method, mixed $args): mixed
    {
        if ($args === true || $args === null) {
            return $target->{$method}();
        }

        if (is_array($args)) {
            if (array_is_list($args)) {
                if (isset($args[0]) && is_array($args[0])) {
                    return $target->{$method}($args);
                }

                return $target->{$method}(...$args);
            }

            return $target->{$method}($args);
        }

        return $target->{$method}($args);
    }

    /**
     * @param  array<string, mixed>  $declaration
     * @param  array<string, mixed>  $parameters
     * @return Builder<Model>|Relation<Model, Model, mixed>
     */
    private static function resolveRoot(array $declaration, array $parameters): Builder|Relation
    {
        $name = is_string($declaration[self::name] ?? null) ? $declaration[self::name] : '';
        $relation = $declaration[self::relation] ?? null;

        if (is_string($relation)) {
            if (! str_contains($relation, '.')) {
                throw new InvalidArgumentException(
                    "Relation query must specify route parameter and relation in 'param.relation' format; '$relation' given."
                );
            }

            [$param, $relationMethod] = explode('.', $relation, 2);
            $owner = $parameters[$param] ?? null;

            if (! $owner instanceof Model) {
                throw new InvalidArgumentException(
                    "Route parameter [$param] must be an instance of Illuminate\\Database\\Eloquent\\Model to query relation [$relationMethod]."
                );
            }

            if (! method_exists($owner, $relationMethod)) {
                throw new LogicException(
                    'Model ['.$owner::class."] does not define relationship method [$relationMethod]."
                );
            }

            $related = $owner->{$relationMethod}();

            if (! $related instanceof Relation && ! $related instanceof Builder) {
                throw new LogicException(
                    "Relationship method [$relationMethod] on [".$owner::class.'] must return an Eloquent Relation or Builder.'
                );
            }

            return $related;
        }

        $modelClass = $declaration[self::model] ?? null;

        if (! is_string($modelClass)) {
            throw new LogicException("Query [$name] must declare either 'model' or 'relation'.");
        }

        if (! is_subclass_of($modelClass, Model::class)) {
            throw new InvalidArgumentException(
                "Declared query model [$modelClass] must be a subclass of Illuminate\\Database\\Eloquent\\Model."
            );
        }

        return $modelClass::query();
    }
}
