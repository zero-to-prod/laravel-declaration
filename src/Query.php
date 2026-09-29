<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use LogicException;
use ReflectionAttribute;
use ReflectionClass;
use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Attributes\Key;
use ZeroToProd\LaravelDeclaration\Attributes\Query\BelongsTo;
use ZeroToProd\LaravelDeclaration\Attributes\Query\Clause;
use ZeroToProd\LaravelDeclaration\Attributes\Query\Count;
use ZeroToProd\LaravelDeclaration\Attributes\Query\Exists;
use ZeroToProd\LaravelDeclaration\Attributes\Query\Fetch;
use ZeroToProd\LaravelDeclaration\Attributes\Query\Find;
use ZeroToProd\LaravelDeclaration\Attributes\Query\Flag;
use ZeroToProd\LaravelDeclaration\Attributes\Query\Paginate;
use ZeroToProd\LaravelDeclaration\Attributes\Query\Spread;
use ZeroToProd\LaravelDeclaration\Attributes\Query\Terminal;
use ZeroToProd\LaravelDeclaration\Attributes\Query\Where;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class Query
{
    use DataModel;

    public const string name = 'name';

    #[Key, Describe([Describe::required => true])]
    public string $name;

    public const string from = 'from';

    #[Key, Describe([Describe::required => true])]
    public string $from;

    public const string select = 'select';

    /** @var list<string>|null */
    #[Key, Clause, Describe([Describe::nullable => true])]
    public ?array $select;

    public const string addSelect = 'addSelect';

    /** @var list<string>|string|null */
    #[Key, Clause, Describe([Describe::nullable => true])]
    public array|string|null $addSelect;

    public const string distinct = 'distinct';

    #[Key, Flag, Describe([Describe::nullable => true])]
    public ?bool $distinct;

    public const string where = 'where';

    #[Key, Where, Describe([Describe::nullable => true])]
    public mixed $where;

    public const string orWhere = 'orWhere';

    #[Key, Where, Describe([Describe::nullable => true])]
    public mixed $orWhere;

    public const string whereNot = 'whereNot';

    #[Key, Where, Describe([Describe::nullable => true])]
    public mixed $whereNot;

    public const string orWhereNot = 'orWhereNot';

    #[Key, Where, Describe([Describe::nullable => true])]
    public mixed $orWhereNot;

    public const string whereKey = 'whereKey';

    #[Key, Clause, Describe([Describe::nullable => true])]
    public mixed $whereKey;

    public const string whereKeyNot = 'whereKeyNot';

    #[Key, Clause, Describe([Describe::nullable => true])]
    public mixed $whereKeyNot;

    public const string whereIn = 'whereIn';

    /** @var array{0: string, 1: list<mixed>}|null */
    #[Key, Spread, Describe([Describe::nullable => true])]
    public ?array $whereIn;

    public const string whereNotIn = 'whereNotIn';

    /** @var array{0: string, 1: list<mixed>}|null */
    #[Key, Spread, Describe([Describe::nullable => true])]
    public ?array $whereNotIn;

    public const string whereNull = 'whereNull';

    /** @var list<string>|string|null */
    #[Key, Clause, Describe([Describe::nullable => true])]
    public array|string|null $whereNull;

    public const string whereNotNull = 'whereNotNull';

    /** @var list<string>|string|null */
    #[Key, Clause, Describe([Describe::nullable => true])]
    public array|string|null $whereNotNull;

    public const string whereBetween = 'whereBetween';

    /** @var array{0: string, 1: array{0: mixed, 1: mixed}}|null */
    #[Key, Spread, Describe([Describe::nullable => true])]
    public ?array $whereBetween;

    public const string whereNotBetween = 'whereNotBetween';

    /** @var array{0: string, 1: array{0: mixed, 1: mixed}}|null */
    #[Key, Spread, Describe([Describe::nullable => true])]
    public ?array $whereNotBetween;

    public const string whereDate = 'whereDate';

    #[Key, Spread, Describe([Describe::nullable => true])]
    public mixed $whereDate;

    public const string whereMonth = 'whereMonth';

    #[Key, Spread, Describe([Describe::nullable => true])]
    public mixed $whereMonth;

    public const string whereDay = 'whereDay';

    #[Key, Spread, Describe([Describe::nullable => true])]
    public mixed $whereDay;

    public const string whereYear = 'whereYear';

    #[Key, Spread, Describe([Describe::nullable => true])]
    public mixed $whereYear;

    public const string whereTime = 'whereTime';

    #[Key, Spread, Describe([Describe::nullable => true])]
    public mixed $whereTime;

    public const string whereColumn = 'whereColumn';

    #[Key, Spread, Describe([Describe::nullable => true])]
    public mixed $whereColumn;

    public const string whereRelation = 'whereRelation';

    #[Key, Spread, Describe([Describe::nullable => true])]
    public mixed $whereRelation;

    public const string orWhereRelation = 'orWhereRelation';

    #[Key, Spread, Describe([Describe::nullable => true])]
    public mixed $orWhereRelation;

    public const string whereDoesntHaveRelation = 'whereDoesntHaveRelation';

    #[Key, Spread, Describe([Describe::nullable => true])]
    public mixed $whereDoesntHaveRelation;

    public const string whereBelongsTo = 'whereBelongsTo';

    /** @var array{0: string, 1?: string}|string|null */
    #[Key, BelongsTo, Describe([Describe::nullable => true])]
    public array|string|null $whereBelongsTo;

    public const string has = 'has';

    #[Key, Spread, Describe([Describe::nullable => true])]
    public mixed $has;

    public const string doesntHave = 'doesntHave';

    #[Key, Clause, Describe([Describe::nullable => true])]
    public mixed $doesntHave;

    public const string with = 'with';

    /** @var list<string>|string|null */
    #[Key, Clause, Describe([Describe::nullable => true])]
    public array|string|null $with;

    public const string without = 'without';

    /** @var list<string>|string|null */
    #[Key, Clause, Describe([Describe::nullable => true])]
    public array|string|null $without;

    public const string withOnly = 'withOnly';

    /** @var list<string>|string|null */
    #[Key, Clause, Describe([Describe::nullable => true])]
    public array|string|null $withOnly;

    public const string withCount = 'withCount';

    /** @var list<string>|string|null */
    #[Key, Clause, Describe([Describe::nullable => true])]
    public array|string|null $withCount;

    public const string withMax = 'withMax';

    /** @var array{0: string, 1: string}|null */
    #[Key, Spread, Describe([Describe::nullable => true])]
    public ?array $withMax;

    public const string withMin = 'withMin';

    /** @var array{0: string, 1: string}|null */
    #[Key, Spread, Describe([Describe::nullable => true])]
    public ?array $withMin;

    public const string withSum = 'withSum';

    /** @var array{0: string, 1: string}|null */
    #[Key, Spread, Describe([Describe::nullable => true])]
    public ?array $withSum;

    public const string withAvg = 'withAvg';

    /** @var array{0: string, 1: string}|null */
    #[Key, Spread, Describe([Describe::nullable => true])]
    public ?array $withAvg;

    public const string withExists = 'withExists';

    /** @var list<string>|string|null */
    #[Key, Clause, Describe([Describe::nullable => true])]
    public array|string|null $withExists;

    public const string scopes = 'scopes';

    #[Key, Clause, Describe([Describe::nullable => true])]
    public mixed $scopes;

    public const string withoutGlobalScope = 'withoutGlobalScope';

    /** @var class-string|null */
    #[Key, Clause, Describe([Describe::nullable => true])]
    public ?string $withoutGlobalScope;

    public const string withoutGlobalScopes = 'withoutGlobalScopes';

    #[Key, Flag, Describe([Describe::nullable => true])]
    public mixed $withoutGlobalScopes;

    public const string orderBy = 'orderBy';

    #[Key, Spread, Describe([Describe::nullable => true])]
    public mixed $orderBy;

    public const string orderByDesc = 'orderByDesc';

    #[Key, Clause, Describe([Describe::nullable => true])]
    public ?string $orderByDesc;

    public const string latest = 'latest';

    #[Key, Flag, Describe([Describe::nullable => true])]
    public string|bool|null $latest;

    public const string oldest = 'oldest';

    #[Key, Flag, Describe([Describe::nullable => true])]
    public string|bool|null $oldest;

    public const string inRandomOrder = 'inRandomOrder';

    #[Key, Flag, Describe([Describe::nullable => true])]
    public ?bool $inRandomOrder;

    public const string groupBy = 'groupBy';

    #[Key, Clause, Describe([Describe::nullable => true])]
    public mixed $groupBy;

    public const string having = 'having';

    #[Key, Spread, Describe([Describe::nullable => true])]
    public mixed $having;

    public const string limit = 'limit';

    #[Key, Clause, Describe([Describe::nullable => true])]
    public ?int $limit;

    public const string offset = 'offset';

    #[Key, Clause, Describe([Describe::nullable => true])]
    public ?int $offset;

    // Terminal methods:
    public const string paginate = 'paginate';

    #[Key, Paginate, Describe([Describe::nullable => true])]
    public mixed $paginate;

    public const string simplePaginate = 'simplePaginate';

    #[Key, Paginate, Describe([Describe::nullable => true])]
    public mixed $simplePaginate;

    public const string cursorPaginate = 'cursorPaginate';

    #[Key, Paginate, Describe([Describe::nullable => true])]
    public mixed $cursorPaginate;

    public const string get = 'get';

    #[Key, Fetch, Describe([Describe::nullable => true])]
    public mixed $get;

    public const string first = 'first';

    #[Key, Fetch, Describe([Describe::nullable => true])]
    public mixed $first;

    public const string firstOrFail = 'firstOrFail';

    #[Key, Fetch, Describe([Describe::nullable => true])]
    public mixed $firstOrFail;

    public const string sole = 'sole';

    #[Key, Fetch, Describe([Describe::nullable => true])]
    public mixed $sole;

    public const string find = 'find';

    #[Key, Find, Describe([Describe::nullable => true])]
    public mixed $find;

    public const string findOrFail = 'findOrFail';

    #[Key, Find, Describe([Describe::nullable => true])]
    public mixed $findOrFail;

    public const string count = 'count';

    #[Key, Count, Describe([Describe::nullable => true])]
    public mixed $count;

    public const string min = 'min';

    #[Key, Terminal, Describe([Describe::nullable => true])]
    public ?string $min;

    public const string max = 'max';

    #[Key, Terminal, Describe([Describe::nullable => true])]
    public ?string $max;

    public const string sum = 'sum';

    #[Key, Terminal, Describe([Describe::nullable => true])]
    public ?string $sum;

    public const string avg = 'avg';

    #[Key, Terminal, Describe([Describe::nullable => true])]
    public ?string $avg;

    public const string exists = 'exists';

    #[Key, Exists, Describe([Describe::nullable => true])]
    public ?bool $exists;

    public const string doesntExist = 'doesntExist';

    #[Key, Exists, Describe([Describe::nullable => true])]
    public ?bool $doesntExist;

    public const string pluck = 'pluck';

    #[Key, Find, Describe([Describe::nullable => true])]
    public mixed $pluck;

    public const string value = 'value';

    #[Key, Terminal, Describe([Describe::nullable => true])]
    public ?string $value;

    public const string clauses = 'clauses';

    /** @var array<string, mixed> */
    #[Describe([
        Describe::assign => [self::class, 'extractClauses'],
    ])]
    public array $clauses;

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function extractClauses(mixed $val, array $context): array
    {
        return array_diff_key($context, [self::name => true, self::from => true]);
    }

    /** @param  array<string, mixed>  $parameters */
    public function run(array $parameters = []): mixed
    {
        $builder = $this->resolveRoot($parameters);
        $terminal = self::get;
        $terminalArgs = ['*'];

        foreach ($this->clauses as $method => $args) {
            if (isset(self::terminals()[$method])) {
                $terminal = $method;
                $terminalArgs = $args;

                continue;
            }

            if (! isset(self::clauses()[$method])) {
                continue;
            }

            self::clauses()[$method]->apply($builder, $method, $args, $parameters);
        }

        return self::terminals()[$terminal]->execute($builder, $terminal, $terminalArgs);
    }

    /** @return array<string, Clause> */
    public static function clauses(): array
    {
        static $clauses = null;

        if ($clauses !== null) {
            return $clauses;
        }

        $clauses = [];
        foreach (new ReflectionClass(self::class)->getProperties() as $property) {
            if ($attribute = $property->getAttributes(Clause::class, ReflectionAttribute::IS_INSTANCEOF)[0] ?? null) {
                $clauses[$property->getName()] = $attribute->newInstance();
            }
        }

        return $clauses;
    }

    /** @return array<string, Terminal> */
    public static function terminals(): array
    {
        static $terminals = null;

        if ($terminals !== null) {
            return $terminals;
        }

        $terminals = [];
        foreach (new ReflectionClass(self::class)->getProperties() as $property) {
            if ($attribute = $property->getAttributes(Terminal::class, ReflectionAttribute::IS_INSTANCEOF)[0] ?? null) {
                $terminals[$property->getName()] = $attribute->newInstance();
            }
        }

        return $terminals;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return Builder<Model>|Relation<Model, Model, mixed>
     */
    private function resolveRoot(array $parameters): Builder|Relation
    {
        if (str_contains($this->from, '.')) {
            [$param, $relation] = explode('.', $this->from, 2);
            $model = $parameters[$param] ?? null;

            if (! $model instanceof Model) {
                throw new InvalidArgumentException(
                    "Route parameter [$param] must be an instance of Illuminate\\Database\\Eloquent\\Model to query relation [$relation]."
                );
            }

            if (! method_exists($model, $relation)) {
                throw new LogicException('Model ['.$model::class."] does not define relationship method [$relation].");
            }

            $root = $model->{$relation}();

            if (! $root instanceof Relation && ! $root instanceof Builder) {
                throw new LogicException(
                    "Method [$relation] on [".$model::class.'] must return an instance of Illuminate\\Database\\Eloquent\\Relations\\Relation or Illuminate\\Database\\Eloquent\\Builder.'
                );
            }

            return $root;
        }

        /** @var class-string<Model> $class */
        $class = $this->from;

        return $class::query();
    }
}
