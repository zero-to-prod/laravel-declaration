<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use BadMethodCallException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use LogicException;
use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class Query
{
    use DataModel;

    public const string name = 'name';

    #[Describe([Describe::required => true])]
    public string $name;

    public const string model = 'model';

    /** @var class-string<Model>|null */
    #[Describe([Describe::nullable => true])]
    public ?string $model;

    public const string relation = 'relation';

    #[Describe([Describe::nullable => true])]
    public ?string $relation;

    public const string clauses = 'clauses';

    /** @var array<string, mixed> */
    #[Describe([Describe::default => [], Describe::assign => [self::class, 'extractClauses']])]
    public array $clauses;

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function extractClauses(mixed $val, array $context): array
    {
        return array_diff_key($context, [
            self::name => true,
            self::model => true,
            self::relation => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $parameters
     *
     * @throws BadMethodCallException
     */
    public function run(array $parameters = []): mixed
    {
        $target = $this->resolveRoot($parameters);
        $result = $target;

        foreach ($this->clauses as $method => $args) {
            $result = $this->dispatchMethod($target, $method, $args);

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
    private function dispatchMethod(Builder|Relation $target, string $method, mixed $args): mixed
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
     * @param  array<string, mixed>  $parameters
     * @return Builder<Model>|Relation<Model, Model, mixed>
     */
    private function resolveRoot(array $parameters): Builder|Relation
    {
        if ($this->relation !== null) {
            if (! str_contains($this->relation, '.')) {
                throw new InvalidArgumentException(
                    "Relation query must specify route parameter and relation in 'param.relation' format; '$this->relation' given."
                );
            }

            [$param, $relationMethod] = explode('.', $this->relation, 2);
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

            $relation = $owner->{$relationMethod}();

            if (! $relation instanceof Relation && ! $relation instanceof Builder) {
                throw new LogicException(
                    "Relationship method [$relationMethod] on [".$owner::class.'] must return an Eloquent Relation or Builder.'
                );
            }

            return $relation;
        }

        if ($this->model === null) {
            throw new LogicException("Query [$this->name] must declare either 'model' or 'relation'.");
        }

        /** @var class-string<Model> $modelClass */
        $modelClass = $this->model;

        if (! is_subclass_of($modelClass, Model::class)) {
            throw new InvalidArgumentException(
                "Declared query model [$modelClass] must be a subclass of Illuminate\\Database\\Eloquent\\Model."
            );
        }

        return $modelClass::query();
    }
}
