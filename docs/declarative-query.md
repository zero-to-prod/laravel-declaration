# Declarative Query Builder — `Illuminate\Database\Eloquent\Builder` Query Pipeline, Execution & Manifest Schema

Source of truth: `vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php` (`laravel/framework` v13.33.0), with `Database/Concerns/BuildsQueries.php`, `Eloquent/Concerns/QueriesRelationships.php`, `Database/Query/Builder.php`, `Eloquent/Model.php`, `Eloquent/Relations/Relation.php`, `Eloquent/Scope.php`, `Pagination/LengthAwarePaginator.php`, `Pagination/Paginator.php`, `Pagination/CursorPaginator.php`, `Database/DatabaseServiceProvider.php`, and Laravel documentation: `docs/repos/laravel/docs/eloquent.md`, `docs/repos/laravel/docs/queries.md`, `docs/repos/laravel/docs/pagination.md`, and `docs/repos/laravel/docs/eloquent-relationships.md`.

Goal: a `queries:` block in `manifest/app.yml` whose **entries declare reusable Eloquent query pipelines**. The reserved key `name` provides the query handle. The reserved key `from` roots the query on an Eloquent model class (`App\Models\Flight`) or a bound route parameter relation (`user.posts`). **Every other key is an `Illuminate\Database\Eloquent\Builder` method name, and its value is that method's argument(s).** A terminal method executes the query (`paginate`, `simplePaginate`, `cursorPaginate`, `get`, `first`, `firstOrFail`, `sole`, `count`, `exists`, `value`, `pluck`), defaulting to `get()`. The package ships one invoker seam class, `DeclaredQuery` (roadmap rule 5), and one DataModel, `Query`. `DeclaredView` resolves declared query handles in `data:` natively, joining parameter binding (§1.1) to view rendering without a hand-written controller. Dynamic request arguments remain in **local scopes** on the Model class (`#[Scope]`), answering the Phase 4 gating question without inventing a DSL (§2.6).

---

## 1. Public API of `Builder::class`

### 1.1 Lifecycle position (when the declaration is read and executed)

```php
// LaravelDeclarationProvider::register()
//     resolveManifest()                      Manifest::$queries hydrated; Query::validate() per entry  <- unknown key, invalid from: LogicException HERE
//     instance(Manifest::class, $Manifest)

// per request dispatch: GET /users/{user}/posts?page=2
Router::findRoute()
    RouteParameterBinder::parameters()        replaceDefaults()
route middleware
    SubstituteBindings                        router.model binds {user} to App\Models\User instance
DeclaredRequest::validateResolved()           metadata.request authorizes & validates before query execution
DeclaredView::__invoke(...$args)              args['data']['posts'] = 'user-posts'
    DeclaredQuery::run('user-posts', $args)   app(Manifest::class)->queries->get('user-posts')->run($args)
        // 1. Root instantiation:
        $model = $args['user'];               <- `from: user.posts`: resolves {user} from route parameters
        $builder = $model->posts();           <- returns HasMany extending Relation, forwarding to Eloquent\Builder (Relation.php:386)
        // 2. Clause application (in declared order):
        $builder->where('status', 'active');  <- Builder::where() -> QueryBuilder::where() (Builder.php:376)
        $builder->with(['author']);           <- Builder::with() (Builder.php:1765)
        $builder->scopes(['published']);      <- Builder::scopes() -> callNamedScope() (Builder.php:1590)
        $builder->latest('created_at');       <- Builder::latest() (Builder.php:458)
        // 3. Terminal execution:
        $result = $builder->paginate(15);     <- Builder::paginate(): reads ?page via Paginator::resolveCurrentPage() (Builder.php:1155)
    ViewController::__invoke(...$args)        array_merge($args['data'], $routeParameters) -> ResponseFactory::view()
```

Consequences, each verified against v13.33.0 with Testbench:

1. **Read at dispatch, executed against live database.** The manifest definition is parsed once into `Manifest::$queries`. The query pipeline executes on demand when `DeclaredView` resolves `data` or when code calls `DeclaredQuery::run()`. Nothing is cached in `route:cache` or `config:cache`.
2. **Parameters are already bound.** `SubstituteBindings` runs in route middleware before action dispatch, so route parameters like `{user}` or `{flight}` are hydrated `Model` instances. If `from: user.posts` is evaluated, `$args['user']` is already the bound `User` instance.
3. **Validation strictly precedes query execution.** If a route specifies `metadata: {request: post-filters}`, `DeclaredRequest` authorizes and validates before `DeclaredView` resolves data references. A 422 Unprocessable Entity or redirect terminates the request without executing database queries.
4. **Native request pagination with zero configuration.** `Builder::paginate()` resolves the current page number directly from the request container via `Paginator::resolveCurrentPage()` (`pagination.md`, line 42). No request extraction or argument mapping DSL is required.
5. **Fail-fast schema validation.** Typos in query clause names (e.g., `wit: [author]`) throw `LogicException` at boot during `resolveManifest()`.

### 1.2 Properties

**Internal state on `Illuminate\Database\Eloquent\Builder`:**

| Property | Type | Visibility | Role in Declaration |
|---|---|---|---|
| `$query` | `Illuminate\Database\Query\Builder` | `protected` | Underlying database query builder; receives forwarded calls via `ForwardsCalls` |
| `$model` | `TModel of Model` | `protected` | The model being queried; set by `setModel()` or `Model::query()` |
| `$eagerLoad` | `array<string, Closure>` | `protected` | Relations to eager load; populated by `with()`, cleared by `without()` |
| `$scopes` | `array<string, Scope\|Closure>` | `protected` | Applied global scopes; modified by `withGlobalScope()` |
| `$removedScopes` | `array<string, Scope\|Closure>` | `protected` | Global scopes explicitly removed by `withoutGlobalScope()` |
| `$passthru` | `string[]` | `protected` | Method names forwarded directly to `$this->toBase()` (aggregates, raw queries) |
| `$propertyPassthru` | `string[]` | `protected` | Properties forwarded directly to `$this->query` (`from`) |
| `$pendingAttributes` | `array<string, mixed>` | `public` | Attributes added to models created through the builder |

**Not declaration targets:**
- `$onDelete`, `$afterQueryCallbacks`, `$onCloneCallbacks`: Runtime closures.
- static `$macros`, `$localMacros`: Global or local macro registries.
- `$propertyPassthru`, `$passthru`: Framework internal dispatch plumbing.

### 1.3 Public methods

**Declaration targets: Root configuration (Reserved keys)**

| Key | Signature | Effect | Source |
|---|---|---|---|
| `name` | `string` | Unique query handle, referenced by `DeclaredView` (`data: {posts: user-posts}`) or `DeclaredQuery::run()` | Package handle |
| `from` | `class-string<Model> \| string` | Query root: Model FQCN (`App\Models\Flight::query()`) or route parameter relation (`user.posts` → `$user->posts()`) | `Model::query()`, `Relation::getQuery()` |

**Declaration targets: Builder clauses (Fluent `$this`)**

| Method | Signature | Effect | Source |
|---|---|---|---|
| `select` | `array\|mixed $columns = ['*']` | Set columns to be selected | `Query\Builder.php:394` |
| `addSelect` | `array\|mixed $column` | Add column(s) to existing select | `Query\Builder.php:431` |
| `distinct` | `bool $distinct = true` | Force query to return distinct results | `Query\Builder.php:511` |
| `where` | `array\|Closure\|Expression\|string $column, $operator = null, $value = null, $boolean = 'and'` | Add basic where clause or compound array of conditions | `Builder.php:376`, `Query\Builder.php:930` |
| `orWhere` | `array\|Closure\|Expression\|string $column, $operator = null, $value = null` | Add "or where" clause | `Builder.php:418`, `Query\Builder.php:1115` |
| `whereNot` | `array\|Closure\|Expression\|string $column, $operator = null, $value = null, $boolean = 'and'` | Add "where not" clause | `Builder.php:432`, `Query\Builder.php:1132` |
| `orWhereNot` | `array\|Closure\|Expression\|string $column, $operator = null, $value = null` | Add "or where not" clause | `Builder.php:446`, `Query\Builder.php:1152` |
| `whereKey` | `mixed $id` | Add where clause on model's primary key | `Builder.php:315` |
| `whereKeyNot` | `mixed $id` | Add where clause excluding model's primary key | `Builder.php:327` |
| `whereIn` | `string $column, mixed $values, string $boolean = 'and', bool $not = false` | Add "where in" clause | `Query\Builder.php:1478` |
| `whereNotIn` | `string $column, mixed $values, string $boolean = 'and'` | Add "where not in" clause | `Query\Builder.php:1516` |
| `whereNull` | `string\|array $columns, string $boolean = 'and', bool $not = false` | Add "where null" clause | `Query\Builder.php:1624` |
| `whereNotNull` | `string\|array $columns, string $boolean = 'and'` | Add "where not null" clause | `Query\Builder.php:1652` |
| `whereBetween` | `string $column, iterable $values, string $boolean = 'and', bool $not = false` | Add "where between" clause | `Query\Builder.php:1666` |
| `whereNotBetween` | `string $column, iterable $values, string $boolean = 'and'` | Add "where not between" clause | `Query\Builder.php:1718` |
| `whereDate` | `string $column, string $operator, mixed $value = null, string $boolean = 'and'` | Add date comparison constraint | `Query\Builder.php:1819` |
| `whereMonth` | `string $column, string $operator, mixed $value = null, string $boolean = 'and'` | Add month comparison constraint | `Query\Builder.php:1869` |
| `whereDay` | `string $column, string $operator, mixed $value = null, string $boolean = 'and'` | Add day comparison constraint | `Query\Builder.php:1886` |
| `whereYear` | `string $column, string $operator, mixed $value = null, string $boolean = 'and'` | Add year comparison constraint | `Query\Builder.php:1902` |
| `whereTime` | `string $column, string $operator, mixed $value = null, string $boolean = 'and'` | Add time comparison constraint | `Query\Builder.php:1918` |
| `whereColumn` | `string\|array $first, ?string $operator = null, ?string $second = null, string $boolean = 'and'` | Add comparison between two columns | `Query\Builder.php:1379` |
| `whereRelation` | `string $relation, string $column, mixed $operator = null, mixed $value = null` | Add where clause on relationship table column | `QueriesRelationships.php:440` |
| `orWhereRelation` | `string $relation, string $column, mixed $operator = null, mixed $value = null` | Add "or where" clause on relationship table column | `QueriesRelationships.php:456` |
| `whereDoesntHaveRelation` | `string $relation, string $column, mixed $operator = null, mixed $value = null` | Add negated relationship column constraint | `QueriesRelationships.php:474` |
| `whereBelongsTo` | `Model\|string $related, ?string $relationshipName = null, string $boolean = 'and'` | Add relationship owner constraint using bound model | `QueriesRelationships.php:735` |
| `has` | `string $relation, string $operator = '>=', int $count = 1, string $boolean = 'and'` | Add relationship existence constraint | `QueriesRelationships.php:27` |
| `doesntHave` | `string $relation, string $boolean = 'and'` | Add relationship absence constraint | `QueriesRelationships.php:164` |
| `with` | `array\|string $relations` | Eager load model relationships | `Builder.php:1765` |
| `without` | `array\|string $relations` | Exclude eager loaded relationships | `Builder.php:1778` |
| `withOnly` | `array\|string $relations` | Reset eager loads to only specified relations | `Builder.php:1790` |
| `withCount` | `array\|string $relations` | Add `{relation}_count` aggregates to query | `QueriesRelationships.php:965` |
| `withMax` | `array\|string $relation, string $column` | Add `{relation}_max_{column}` aggregate | `QueriesRelationships.php:981` |
| `withMin` | `array\|string $relation, string $column` | Add `{relation}_min_{column}` aggregate | `QueriesRelationships.php:996` |
| `withSum` | `array\|string $relation, string $column` | Add `{relation}_sum_{column}` aggregate | `QueriesRelationships.php:1011` |
| `withAvg` | `array\|string $relation, string $column` | Add `{relation}_avg_{column}` aggregate | `QueriesRelationships.php:1026` |
| `withExists` | `array\|string $relation` | Add `{relation}_exists` boolean subquery | `QueriesRelationships.php:1041` |
| `scopes` | `array\|string $scopes` | Call local model scopes with arguments | `Builder.php:1590` |
| `withoutGlobalScope` | `string $scope` | Remove specific global scope class | `Builder.php:191` |
| `withoutGlobalScopes` | `?array $scopes = null` | Remove all or specific global scopes | `Builder.php:204` |
| `orderBy` | `string $column, string $direction = 'asc'` | Add order by clause | `Query\Builder.php:3033` |
| `orderByDesc` | `string $column` | Add descending order by clause | `Query\Builder.php:3058` |
| `latest` | `?string $column = null` | Order by column descending (default `created_at`) | `Builder.php:458` |
| `oldest` | `?string $column = null` | Order by column ascending (default `created_at`) | `Builder.php:471` |
| `inRandomOrder` | `string $seed = ''` | Order query randomly | `Query\Builder.php:3111` |
| `groupBy` | `array\|string ...$groups` | Add group by clause | `Query\Builder.php:2988` |
| `having` | `string $column, ?string $operator = null, ?string $value = null, string $boolean = 'and'` | Add having clause | `Query\Builder.php:3125` |
| `limit` | `int $value` | Set query row limit | `Query\Builder.php:3231` |
| `offset` | `int $value` | Set query row offset | `Query\Builder.php:3245` |

**Declaration targets: Terminal execution methods (Executed last)**

| Method | Signature | Returns | Effect | Source |
|---|---|---|---|---|
| `paginate` | `?int $perPage = null, array $columns = ['*'], string $pageName = 'page', ?int $page = null` | `LengthAwarePaginator` | Paginate query using request `?page` | `Builder.php:1155` |
| `simplePaginate` | `?int $perPage = null, array $columns = ['*'], string $pageName = 'page', ?int $page = null` | `Paginator` | Efficient pagination without total count | `Builder.php:1217` |
| `cursorPaginate` | `?int $perPage = null, array $columns = ['*'], string $cursorName = 'cursor', ?Cursor $cursor = null` | `CursorPaginator` | Cursor-based keyset pagination | `Builder.php:1255` |
| `get` | `array $columns = ['*']` | `Collection<int, TModel>` | Execute query and retrieve all matching models (default terminal) | `Builder.php:906` |
| `first` | `array $columns = ['*']` | `?TModel` | Retrieve first matching record or `null` | `BuildsQueries.php:406` |
| `firstOrFail` | `array $columns = ['*']` | `TModel` | Retrieve first record or throw `ModelNotFoundException` | `Builder.php:838` |
| `sole` | `array $columns = ['*']` | `TModel` | Retrieve exactly one record; throws if 0 or >1 | `BuildsQueries.php:438` |
| `find` | `mixed $id, array $columns = ['*']` | `?TModel` | Find record by primary key | `Builder.php:589` |
| `findOrFail` | `mixed $id, array $columns = ['*']` | `TModel` | Find record by primary key or throw `ModelNotFoundException` | `Builder.php:645` |
| `count` | `string $columns = '*'` | `int` | Count matching records | `Query\Builder.php:4057` |
| `min` | `string $column` | `mixed` | Retrieve minimum column value | `Query\Builder.php:4093` |
| `max` | `string $column` | `mixed` | Retrieve maximum column value | `Query\Builder.php:4080` |
| `sum` | `string $column` | `mixed` | Retrieve sum of column values | `Query\Builder.php:4119` |
| `avg` | `string $column` | `mixed` | Retrieve average of column values | `Query\Builder.php:4106` |
| `exists` | `(): bool` | `bool` | Determine if any matching records exist | `Query\Builder.php:4142` |
| `doesntExist` | `(): bool` | `bool` | Determine if no matching records exist | `Query\Builder.php:4152` |
| `pluck` | `string $column, ?string $key = null` | `BaseCollection` | Pluck list of column values | `Builder.php:1071` |
| `value` | `string $column` | `mixed` | Retrieve single column value from first row | `BuildsQueries.php:477` |

**Not declaration targets**

| Method | Why no key |
|---|---|
| `update`, `delete`, `forceDelete`, `upsert`, `increment`, `decrement`, `touch` | Mutations / writes; queries declared in the manifest are read-side pipelines |
| `create`, `createQuietly`, `forceCreate`, `make`, `firstOrCreate`, `firstOrNew`, `updateOrCreate` | Model factory / persistence operations |
| `chunk`, `chunkById`, `each`, `tap`, `pipe`, `when`, `unless`, `afterQuery`, `onDelete` | Require `Closure` arguments; dynamic logic belongs in PHP local scopes (§2.6) |
| `cursor`, `lazy`, `lazyById` | Return unbuffered streaming generators; incompatible with view serialization |
| `toBase`, `getQuery`, `setQuery`, `getModel`, `setModel`, `applyScopes`, `eagerLoadRelations` | Internal plumbing methods |
| `dd`, `dump`, `toSql`, `toRawSql`, `explain` | Debugging methods |

### 1.4 How a declaration reaches the builder

```php
// 1. Root instantiation: Model FQCN or Bound Parameter Relation
if (is_subclass_of($from, Model::class)) {
    $builder = $from::query(); // Model.php:1905: (new static)->newQuery()
} elseif (str_contains($from, '.')) {
    [$param, $relation] = explode('.', $from, 2);
    $model = $parameters[$param] ?? null;
    $builder = $model->{$relation}(); // HasMany extends Relation (Relation.php:386)
}

// 2. QueryBuilder.php:930 — where clause delegation
public function where($column, $operator = null, $value = null, $boolean = 'and')
{
    if (is_array($column)) {
        return $this->addArrayOfWheres($column, $boolean); // recursive nested grouping
    }
    [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);
    $this->wheres[] = compact('type', 'column', 'operator', 'value', 'boolean');
    return $this;
}

// 3. Builder.php:1590 — local scopes delegation
public function scopes($scopes)
{
    $builder = $this;
    foreach (Arr::wrap($scopes) as $scope => $parameters) {
        if (is_int($scope)) {
            [$scope, $parameters] = [$parameters, []];
        }
        $builder = $builder->callNamedScope($scope, Arr::wrap($parameters));
    }
    return $builder;
}

// 4. Builder.php:2307 — dynamic method call & scope forwarding
public function __call($method, $parameters)
{
    if ($this->hasNamedScope($method)) {
        return $this->callNamedScope($method, $parameters);
    }
    if (in_array(strtolower($method), $this->passthru)) {
        return $this->toBase()->{$method}(...$parameters);
    }
    $this->forwardCallTo($this->query, $method, $parameters);
    return $this;
}

// 5. Builder.php:1155 — terminal pagination
public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null, $total = null)
{
    $page = $page ?: Paginator::resolveCurrentPage($pageName);
    $total = $total ?? $this->getCountForPagination();
    $results = $total ? $this->forPage($page, $perPage)->get($columns) : $this->model->newCollection();
    return $this->paginator($results, $total, $perPage, $page, [
        'path' => Paginator::resolveCurrentPath(),
        'pageName' => $pageName,
    ]);
}
```

Consequences, each verified against v13.33.0 with Testbench:

1. **Relations forward directly to `Builder`.** Calling `where()`, `with()`, or `paginate()` on a `HasMany` or `BelongsToMany` relation delegates via `Relation::__call()` (`Relation.php:465`) to the underlying `Eloquent\Builder`. Relation constraints (such as `user_id = ?`) are preserved and merged before declared wheres.
2. **`scopes` applies clean logical grouping.** `Builder::callNamedScope()` slices `where` clauses using `addNewWheresWithinGroup()` (`Builder.php:1645`), isolating scope constraints inside parenthesized nested queries so `or` operators cannot taint outer query clauses (`eloquent.md`, line 1607).
3. **Compound `where` shapes pass without translation.** Laravel accepts:
   - Tuple list: `where: [[status, active], [price, '>', 100]]`
   - Associative map: `where: {status: active, delayed: false}`
   - Single clause: `where: [status, active]` or `where: [status, '!=', cancelled]`
   All delegate directly to `QueryBuilder::addArrayOfWheres()` (`Query/Builder.php:1039`).
4. **Paginator resolves URL parameters natively.** `paginate(15)` automatically includes query string parameters via `Paginator::resolveCurrentPath()`, preserving existing filter query params across page transitions (`pagination.md`, line 42).
5. **Zero switch/match dispatch overhead via PHP attributes.** Instead of routing builder methods through monolithic `switch` or `match` statements, each declared method property is decorated with an attribute (`#[Clause]`, `#[Spread]`, `#[Where]`, `#[BelongsTo]`, `#[Flag]`, `#[Terminal]`, `#[Paginate]`, `#[Fetch]`, `#[Find]`, `#[Count]`, `#[Exists]`). Invocation delegates directly to the attribute's `apply()` or `execute()` polymorphic contract.

### 1.5 The PHP this replaces

```php
namespace App\Queries;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class UserPosts
{
    public function __invoke(User $user): LengthAwarePaginator
    {
        return $user->posts()
            ->where('status', 'published')
            ->with(['author', 'tags'])
            ->withCount(['comments'])
            ->scopes(['featured'])
            ->latest('published_at')
            ->paginate(15);
    }
}
```

```yaml
queries:
  - name: user-posts
    from: user.posts
    where: [status, published]
    with: [author, tags]
    withCount: [comments]
    scopes: [featured]
    latest: published_at
    paginate: 15
```

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ZeroToProd\LaravelDeclaration\DeclaredModel;

final class User extends DeclaredModel
{
    /** @return HasMany<Post, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}

final class Post extends DeclaredModel
{
    /** Local scope stays clean PHP */
    #[Scope]
    protected function featured(Builder $query): void
    {
        $query->where('is_featured', true);
    }
}
```

---

## 2. Manifest schema proposal

### 2.1 Design rule

> **A `queries` entry is an `Illuminate\Database\Eloquent\Builder` execution pipeline.** The reserved key `name` names the query. The reserved key `from` roots the query on a model class or route parameter relation. **Every other key is an `Illuminate\Database\Eloquent\Builder` method name, and its value is that method's argument(s).** A terminal execution method (`paginate`, `simplePaginate`, `cursorPaginate`, `get`, `first`, `firstOrFail`, `sole`, `count`, `exists`, `value`, `pluck`) executes the pipeline and returns the result, defaulting to `get()`. Dynamic arguments live in **local scopes** on the Model class.

This adheres strictly to Roadmap §2 Rule 1 ("Key = method name. No invented verbs."):
1. **The method is the system of record.** `where`, `with`, `latest`, `paginate` are verbatim Laravel method names. No pseudo-SQL syntax (`SELECT * WHERE`) or custom AST is introduced.
2. **Local scopes encapsulate dynamic logic.** Instead of inventing a DSL for request parameters (`argument: request.query('sort')`), dynamic filters remain PHP methods on the model (`#[Scope]`). The YAML passes literal arguments or calls the scope by name.
3. **Seamless `DeclaredView` integration.** `DeclaredView` inspects `$Manifest->queries->get($value)`: if a `data` key names a declared query, it runs with the route parameters. If not, it falls back to reference resolution or literal values.

### 2.2 Values

| Key | Attribute | Value | Laravel Call | Rules |
|---|---|---|---|---|
| `name` | `#[Key]` | `string` | — | Required handle, regex `^[A-Za-z0-9_-]+$` |
| `from` | `#[Key]` | `class-string<Model> \| string` | `Model::query()` or `$param->{$relation}()` | Model FQCN or `param.relation` format |
| `select`, `addSelect` | `#[Clause]` | `list<string>` | `select($columns)` | Array of column names |
| `distinct` | `#[Flag]` | `bool` | `distinct()` | When true, applies distinct |
| `where`, `orWhere` | `#[Where]` | `list<mixed> \| map<string, mixed>` | `where(...$args)` | `[col, val]`, `[col, op, val]`, `{col: val}`, or list of tuples |
| `whereIn`, `whereNotIn` | `#[Spread]` | `[string, list<mixed>]` | `whereIn($col, $vals)` | First item is column, second is list of values |
| `whereNull`, `whereNotNull` | `#[Clause]` | `string \| list<string>` | `whereNull($cols)` | Column name or array of columns |
| `whereBetween`, `whereNotBetween` | `#[Spread]` | `[string, [mixed, mixed]]` | `whereBetween($col, [$min, $max])` | Column and 2-element range |
| `whereDate`, `whereMonth`, `whereDay`, `whereYear`, `whereTime` | `#[Spread]` | `[string, string] \| [string, string, mixed]` | `whereDate($col, $op, $val)` | Date comparison parameters |
| `whereColumn` | `#[Spread]` | `[string, string] \| [string, string, string]` | `whereColumn($first, $op, $second)` | Cross-column comparison |
| `whereRelation`, `orWhereRelation`, `whereDoesntHaveRelation` | `#[Spread]` | `[string, string, mixed] \| [string, string, string, mixed]` | `whereRelation($rel, $col, $op, $val)` | Relationship table filter (`eloquent-relationships.md`, line 1507) |
| `whereBelongsTo` | `#[BelongsTo]` | `string \| [string, string]` | `whereBelongsTo($parameters[$param], $relation)` | Name of bound route parameter containing owner model, or `[param, relation]` tuple |
| `has` | `#[Spread]` | `string \| [string, string, int]` | `has($rel, $op, $count)` | Relationship existence check |
| `doesntHave` | `#[Clause]` | `string` | `doesntHave($rel)` | Relationship absence check |
| `with`, `without`, `withOnly` | `#[Clause]` | `string \| list<string>` | `with($relations)` | Eager load relationship names (`eloquent-relationships.md`, line 1936) |
| `withCount` | `#[Clause]` | `string \| list<string>` | `withCount($relations)` | Counts related records into `{relation}_count` |
| `withMax`, `withMin`, `withSum`, `withAvg` | `#[Spread]` | `[string, string]` | `withMax($rel, $col)` | Aggregate related column value |
| `withExists` | `#[Clause]` | `string \| list<string>` | `withExists($relations)` | Boolean existence subquery |
| `scopes` | `#[Clause]` | `string \| list<string> \| map<string, mixed>` | `scopes($scopes)` | Local model scopes (`eloquent.md`, line 1559) |
| `withoutGlobalScope` | `#[Clause]` | `class-string<Scope>` | `withoutGlobalScope($scope)` | Scope class to exclude (`eloquent.md`, line 1527) |
| `withoutGlobalScopes` | `#[Flag]` | `bool \| list<class-string<Scope>>` | `withoutGlobalScopes($scopes)` | Remove all or specific global scopes |
| `orderBy` | `#[Spread]` | `string \| [string, string]` | `orderBy($col, $dir)` | Column and optional direction (`queries.md`, line 1222) |
| `orderByDesc` | `#[Clause]` | `string` | `orderByDesc($col)` | Descending order by column |
| `latest`, `oldest` | `#[Flag]` | `string \| bool \| null \| ~` | `latest($column)` | Order by column desc/asc (`true`, `null`, or `~` for default `created_at`) |
| `inRandomOrder` | `#[Flag]` | `bool \| string` | `inRandomOrder($seed)` | Random order with optional seed |
| `groupBy` | `#[Clause]` | `string \| list<string>` | `groupBy(...$groups)` | Group by columns |
| `having` | `#[Spread]` | `[string, string, mixed]` | `having($col, $op, $val)` | Having clause |
| `limit`, `offset` | `#[Clause]` | `int` | `limit($val)` | Numeric limit/offset |
| `paginate` | `#[Paginate]` | `int \| bool \| list<mixed>` | `paginate($perPage, ...)` | **Terminal:** `LengthAwarePaginator` using request `?page` |
| `simplePaginate` | `#[Paginate]` | `int \| bool \| list<mixed>` | `simplePaginate($perPage, ...)` | **Terminal:** `Paginator` without total count |
| `cursorPaginate` | `#[Paginate]` | `int \| bool \| list<mixed>` | `cursorPaginate($perPage, ...)` | **Terminal:** `CursorPaginator` using keyset |
| `get` | `#[Fetch]` | `bool \| list<string>` | `get($columns)` | **Terminal:** `Collection<TModel>` (default if omitted) |
| `first`, `firstOrFail`, `sole` | `#[Fetch]` | `bool \| list<string>` | `first($columns)` | **Terminal:** Single model instance |
| `find`, `findOrFail` | `#[Find]` | `mixed` | `find($id)` | **Terminal:** Single model by primary key |
| `count` | `#[Count]` | `bool \| string` | `count($column)` | **Terminal:** Scalar integer count |
| `exists`, `doesntExist` | `#[Exists]` | `bool` | `exists()` | **Terminal:** Scalar boolean |
| `value` | `#[Terminal]` | `string` | `value($column)` | **Terminal:** Single scalar column value |
| `pluck` | `#[Find]` | `string \| [string, string]` | `pluck($col, $key)` | **Terminal:** Flat list or keyed collection |
| `min`, `max`, `sum`, `avg` | `#[Terminal]` | `string` | `min($column)` | **Terminal:** Aggregate scalar value |

### 2.3 Full example

```yaml
router:
  model:
    user: App\Models\User                        # {user} -> User::resolveRouteBinding()

models:
  - class: App\Models\User
    table: users
    fillable: [name, email]

  - class: App\Models\Post
    table: posts
    fillable: [user_id, title, status, is_featured, published_at]
    casts:
      is_featured: boolean
      published_at: datetime

queries:
  # Query 1: Route parameter relation with scopes and pagination
  - name: user-posts
    from: user.posts                             # route parameter {user} -> $user->posts()
    where: [status, published]                   # -> where('status', '=', 'published')
    with: [author]                               # -> with(['author'])
    withCount: [comments]                        # -> withCount(['comments'])
    scopes: [featured]                           # -> local scope featured() on Post
    latest: published_at                         # -> latest('published_at')
    paginate: 10                                 # terminal -> paginate(10)

  # Query 2: Direct model root with scalar aggregate terminal
  - name: active-flight-count
    from: App\Models\Flight                      # model root -> Flight::query()
    where: [status, active]
    count: true                                  # terminal -> count()

routes:
  - path: "users/{user}/posts"
    methods: GET
    action: ZeroToProd\LaravelDeclaration\DeclaredView
    name: users.posts
    middleware: [web]                            # SubstituteBindings binds {user}
    setDefaults:
      view: users.posts
      data:
        title: User Articles                     # literal string
        posts: user-posts                        # declared query handle!
```

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ZeroToProd\LaravelDeclaration\DeclaredModel;

final class User extends DeclaredModel
{
    /** @return HasMany<Post, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}

final class Post extends DeclaredModel
{
    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    #[Scope]
    protected function featured(Builder $query): void
    {
        $query->where('is_featured', true);
    }
}
```

### 2.4 Key → member → signature map

| YAML key | Attribute | `Builder` / `QueryBuilder` Member | PHP Signature | Return Type | Absent Default |
|---|---|---|---|---|---|
| `name` | `#[Key]` | — (reserved) | string | — | required |
| `from` | `#[Key]` | `Model::query()` / `Relation` | string | `Builder` | required |
| `select` | `#[Clause]` | `select($columns)` | `array $columns = ['*']` | `$this` | `*` |
| `addSelect` | `#[Clause]` | `addSelect($column)` | `array\|string $column` | `$this` | omitted |
| `distinct` | `#[Flag]` | `distinct()` | `bool $distinct = true` | `$this` | false |
| `where` | `#[Where]` | `where($col, $op, $val, $bool)` | `mixed $col, $op = null, $val = null` | `$this` | omitted |
| `orWhere` | `#[Where]` | `orWhere($col, $op, $val)` | `mixed $col, $op = null, $val = null` | `$this` | omitted |
| `whereNot` | `#[Where]` | `whereNot($col, $op, $val)` | `mixed $col, $op = null, $val = null` | `$this` | omitted |
| `orWhereNot` | `#[Where]` | `orWhereNot($col, $op, $val)` | `mixed $col, $op = null, $val = null` | `$this` | omitted |
| `whereKey` | `#[Clause]` | `whereKey($id)` | `mixed $id` | `$this` | omitted |
| `whereKeyNot` | `#[Clause]` | `whereKeyNot($id)` | `mixed $id` | `$this` | omitted |
| `whereIn` | `#[Spread]` | `whereIn($col, $vals)` | `string $col, array $vals` | `$this` | omitted |
| `whereNotIn` | `#[Spread]` | `whereNotIn($col, $vals)` | `string $col, array $vals` | `$this` | omitted |
| `whereNull` | `#[Clause]` | `whereNull($cols)` | `string\|array $cols` | `$this` | omitted |
| `whereNotNull` | `#[Clause]` | `whereNotNull($cols)` | `string\|array $cols` | `$this` | omitted |
| `whereBetween` | `#[Spread]` | `whereBetween($col, $vals)` | `string $col, iterable $vals` | `$this` | omitted |
| `whereNotBetween` | `#[Spread]` | `whereNotBetween($col, $vals)`| `string $col, iterable $vals` | `$this` | omitted |
| `whereDate` | `#[Spread]` | `whereDate($col, $op, $val)` | `string $col, string $op, mixed $val` | `$this` | omitted |
| `whereMonth` | `#[Spread]` | `whereMonth($col, $op, $val)` | `string $col, string $op, mixed $val` | `$this` | omitted |
| `whereDay` | `#[Spread]` | `whereDay($col, $op, $val)` | `string $col, string $op, mixed $val` | `$this` | omitted |
| `whereYear` | `#[Spread]` | `whereYear($col, $op, $val)` | `string $col, string $op, mixed $val` | `$this` | omitted |
| `whereTime` | `#[Spread]` | `whereTime($col, $op, $val)` | `string $col, string $op, mixed $val` | `$this` | omitted |
| `whereColumn` | `#[Spread]` | `whereColumn($first, $op, $sec)`| `string $first, string $op, string $sec`| `$this` | omitted |
| `whereRelation` | `#[Spread]` | `whereRelation($rel, $col, ...)`| `string $rel, string $col, mixed $val` | `$this` | omitted |
| `orWhereRelation` | `#[Spread]` | `orWhereRelation($rel, $col, ...)`| `string $rel, string $col, mixed $val`| `$this` | omitted |
| `whereDoesntHaveRelation` | `#[Spread]` | `whereDoesntHaveRelation(...)` | `string $rel, string $col, mixed $val`| `$this` | omitted |
| `whereBelongsTo` | `#[BelongsTo]` | `whereBelongsTo($model, $relation)` | `Model $related, ?string $relationshipName = null` | `$this` | omitted |
| `has` | `#[Spread]` | `has($rel, $op, $count)` | `string $rel, string $op, int $count` | `$this` | omitted |
| `doesntHave` | `#[Clause]` | `doesntHave($rel)` | `string $rel` | `$this` | omitted |
| `with` | `#[Clause]` | `with($relations)` | `array\|string $relations` | `$this` | omitted |
| `without` | `#[Clause]` | `without($relations)` | `array\|string $relations` | `$this` | omitted |
| `withOnly` | `#[Clause]` | `withOnly($relations)` | `array\|string $relations` | `$this` | omitted |
| `withCount` | `#[Clause]` | `withCount($relations)` | `array\|string $relations` | `$this` | omitted |
| `withMax` | `#[Spread]` | `withMax($rel, $col)` | `string $rel, string $col` | `$this` | omitted |
| `withMin` | `#[Spread]` | `withMin($rel, $col)` | `string $rel, string $col` | `$this` | omitted |
| `withSum` | `#[Spread]` | `withSum($rel, $col)` | `string $rel, string $col` | `$this` | omitted |
| `withAvg` | `#[Spread]` | `withAvg($rel, $col)` | `string $rel, string $col` | `$this` | omitted |
| `withExists` | `#[Clause]` | `withExists($relations)` | `array\|string $relations` | `$this` | omitted |
| `scopes` | `#[Clause]` | `scopes($scopes)` | `array\|string $scopes` | `$this` | omitted |
| `withoutGlobalScope` | `#[Clause]` | `withoutGlobalScope($scope)` | `string $scope` | `$this` | omitted |
| `withoutGlobalScopes`| `#[Flag]` | `withoutGlobalScopes($scopes)`| `?array $scopes = null` | `$this` | omitted |
| `orderBy` | `#[Spread]` | `orderBy($column, $dir)` | `string $column, string $direction` | `$this` | omitted |
| `orderByDesc` | `#[Clause]` | `orderByDesc($column)` | `string $column` | `$this` | omitted |
| `latest` | `#[Flag]` | `latest($column)` | `string\|bool\|null $column = null` | `$this` | omitted |
| `oldest` | `#[Flag]` | `oldest($column)` | `string\|bool\|null $column = null` | `$this` | omitted |
| `inRandomOrder` | `#[Flag]` | `inRandomOrder()` | `string $seed = ''` | `$this` | omitted |
| `groupBy` | `#[Clause]` | `groupBy(...$groups)` | `array ...$groups` | `$this` | omitted |
| `having` | `#[Spread]` | `having($col, $op, $val)` | `string $col, string $op, mixed $val` | `$this` | omitted |
| `limit` | `#[Clause]` | `limit($value)` | `int $value` | `$this` | omitted |
| `offset` | `#[Clause]` | `offset($value)` | `int $value` | `$this` | omitted |
| `paginate` | `#[Paginate]` | `paginate($perPage, ...)` | `int\|bool\|array $perPage = null` | `LengthAwarePaginator` | terminal |
| `simplePaginate` | `#[Paginate]` | `simplePaginate($perPage, ...)` | `int\|bool\|array $perPage = null` | `Paginator` | terminal |
| `cursorPaginate` | `#[Paginate]` | `cursorPaginate($perPage, ...)` | `int\|bool\|array $perPage = null` | `CursorPaginator` | terminal |
| `get` | `#[Fetch]` | `get($columns)` | `array $columns = ['*']` | `Collection<TModel>` | default terminal |
| `first` | `#[Fetch]` | `first($columns)` | `array $columns = ['*']` | `?TModel` | terminal |
| `firstOrFail` | `#[Fetch]` | `firstOrFail($columns)` | `array $columns = ['*']` | `TModel` | terminal |
| `sole` | `#[Fetch]` | `sole($columns)` | `array $columns = ['*']` | `TModel` | terminal |
| `find` | `#[Find]` | `find($id, $columns)` | `mixed $id` | `?TModel` | terminal |
| `findOrFail` | `#[Find]` | `findOrFail($id, $columns)` | `mixed $id` | `TModel` | terminal |
| `count` | `#[Count]` | `count($columns)` | `string $columns = '*'` | `int` | terminal |
| `min` | `#[Terminal]` | `min($column)` | `string $column` | `mixed` | terminal |
| `max` | `#[Terminal]` | `max($column)` | `string $column` | `mixed` | terminal |
| `sum` | `#[Terminal]` | `sum($column)` | `string $column` | `mixed` | terminal |
| `avg` | `#[Terminal]` | `avg($column)` | `string $column` | `mixed` | terminal |
| `exists` | `#[Exists]` | `exists()` | `(): bool` | `bool` | terminal |
| `doesntExist` | `#[Exists]` | `doesntExist()` | `(): bool` | `bool` | terminal |
| `value` | `#[Terminal]` | `value($column)` | `string $column` | `mixed` | terminal |
| `pluck` | `#[Find]` | `pluck($column, $key)` | `string $column, ?string $key` | `BaseCollection` | terminal |

### 2.5 Execution algorithm

The package defines two attribute hierarchies in `ZeroToProd\LaravelDeclaration\Attributes\Query`:

1. **`Clause` attributes** decorate query builder pipeline methods. Each implements `apply(Builder|Relation $builder, string $method, mixed $args, array $parameters = []): void`:
   - `#[Clause]`: Direct single-argument invocation `$builder->{$method}($args)` (e.g., `select`, `with`, `scopes`, `limit`, `offset`).
   - `#[Spread]`: Unpacked multiple-argument invocation `$builder->{$method}(...(array) $args)` (e.g., `whereIn`, `whereBetween`, `whereDate`, `whereRelation`, `has`, `withMax`, `orderBy`).
   - `#[Where]`: Shape-aware where normalization (unpacks flat lists `['status', 'active']` to `where(...$args)` while preserving nested condition lists and associative maps for `addArrayOfWheres`).
   - `#[BelongsTo]`: Parameter-aware owner model resolution from bound route parameters (`$parameters[$param]`).
   - `#[Flag]`: Boolean flag and optional-parameter dispatch (`distinct`, `latest`, `oldest`, `inRandomOrder`, `withoutGlobalScopes`).

2. **`Terminal` attributes** decorate execution methods that terminate the pipeline. Each implements `execute(Builder|Relation $builder, string $method, mixed $args): mixed`:
   - `#[Terminal]`: Base terminal invocation passing `$args` directly (`min`, `max`, `sum`, `avg`, `value`).
   - `#[Paginate]`: Request-driven paginator terminal execution (`paginate`, `simplePaginate`, `cursorPaginate`).
   - `#[Fetch]`: Collection and single-model terminal execution (`get`, `first`, `firstOrFail`, `sole`).
   - `#[Find]`: Primary-key lookup and pluck terminal execution (`find`, `findOrFail`, `pluck`).
   - `#[Count]`: Aggregate record count execution (`count`).
   - `#[Exists]`: Zero-argument existence check execution (`exists`, `doesntExist`).

`Query` reflects its properties once, caching clause and terminal maps via `ReflectionAttribute::IS_INSTANCEOF`. This eliminates hardcoded terminal arrays and replaces all `switch`/`match` statements with polymorphic execution.

`src/Manifest.php` gains `$queries`:

```php
/** @var Collection<string, Query> */
#[Describe([
    Describe::cast => [self::class, 'mapOf'],
    'type' => Query::class,
    'key_by' => Query::name,
])]
public Collection $queries;
```

`src/DeclaredQuery.php` is the invoker seam:

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use LogicException;

final class DeclaredQuery
{
    /** @param  array<string, mixed>  $parameters */
    public static function run(string $name, array $parameters = []): mixed
    {
        $manifest = app(Manifest::class);

        if (! $manifest->queries->has($name)) {
            throw new LogicException("The declared query [$name] does not exist.");
        }

        return $manifest->queries->get($name)->run($parameters);
    }
}
```

`DeclaredView::__invoke` gains 3 lines to resolve query handles before reference resolution:

```php
        $manifest = app(Manifest::class);

        $args['data'] = array_map(
            static function (mixed $value) use ($parameters, $manifest): mixed {
                if (is_string($value) && $manifest->queries->has($value)) {
                    return DeclaredQuery::run($value, $parameters);
                }

                return is_string($value) && str_contains(Str::before($value, '@'), '\\')
                    ? app()->call($value, $parameters)
                    : $value;
            },
            $data,
        );
```

### 2.6 Notes / non-goals

- **Dynamic request arguments stay in local scopes.** Gating Phase 4 was the question of request-derived arguments. Inventing syntax like `where: [status, request.status]` creates a custom expression engine that violates Roadmap §2 Rule 1. Instead, dynamic filtering belongs in a **local scope** on the Model class (`#[Scope]`):
  ```php
  #[Scope]
  protected function filtered(Builder $query, DeclaredRequest $request): void
  {
      if ($status = $request->validated('status')) {
          $query->where('status', $status);
      }
  }
  ```
  The manifest simply declares `scopes: [filtered]`. Scope parameters are resolved via PHP, while the query manifest retains clean, declarative literals.
- **Write / mutation queries are non-goals.** `insert`, `update`, `delete`, `upsert`, and `touch` do not belong in view data declarations. Mutations are dispatched in dedicated actions, jobs, or command handlers.
- **Raw SQL expressions are non-goals.** `whereRaw`, `selectRaw`, `havingRaw`, and `orderByRaw` require unescaped SQL strings. Storing raw SQL in YAML bypasses schema validation and creates SQL injection risks if mishandled. Raw expressions belong encapsulated within model scopes.
- **Subquery closures remain PHP.** Methods like `whereExists(Closure)` or `where(Closure)` require anonymous functions. In accordance with Roadmap §2 Rule 3, anything requiring Closures remains a referenced PHP callable or scope.
- **No caching.** Queries are evaluated at dispatch per request against live database connections. Result sets are never cached by `route:cache` or `config:cache`.

---

## 3. Implementation plan

1. **`src/Attributes/Query/*.php`** (new): Declarative method execution attributes implementing polymorphic dispatch and replacing all `switch`/`match` statements:

   ```php
   // src/Attributes/Query/Clause.php
   namespace ZeroToProd\LaravelDeclaration\Attributes\Query;

   use Attribute;
   use Illuminate\Database\Eloquent\Builder;
   use Illuminate\Database\Eloquent\Relations\Relation;

   #[Attribute(Attribute::TARGET_PROPERTY)]
   class Clause
   {
       /** @param  array<string, mixed>  $parameters */
       public function apply(Builder|Relation $builder, string $method, mixed $args, array $parameters = []): void
       {
           $builder->{$method}($args);
       }
   }

   // src/Attributes/Query/Spread.php
   #[Attribute(Attribute::TARGET_PROPERTY)]
   final class Spread extends Clause
   {
       public function apply(Builder|Relation $builder, string $method, mixed $args, array $parameters = []): void
       {
           $builder->{$method}(...(array) $args);
       }
   }

   // src/Attributes/Query/Where.php
   #[Attribute(Attribute::TARGET_PROPERTY)]
   final class Where extends Clause
   {
       public function apply(Builder|Relation $builder, string $method, mixed $args, array $parameters = []): void
       {
           if (is_array($args) && array_is_list($args) && ! is_array($args[0] ?? null)) {
               $builder->{$method}(...$args);
           } else {
               $builder->{$method}($args);
           }
       }
   }

   // src/Attributes/Query/BelongsTo.php
   #[Attribute(Attribute::TARGET_PROPERTY)]
   final class BelongsTo extends Clause
   {
       public function apply(Builder|Relation $builder, string $method, mixed $args, array $parameters = []): void
       {
           [$param, $relation] = is_array($args) ? [$args[0], $args[1] ?? null] : [$args, null];
           $owner = $parameters[$param] ?? null;

           if (! $owner instanceof \Illuminate\Database\Eloquent\Model) {
               throw new \InvalidArgumentException("Route parameter [$param] must be an instance of Illuminate\\Database\\Eloquent\\Model.");
           }

           $relation !== null
               ? $builder->whereBelongsTo($owner, $relation)
               : $builder->whereBelongsTo($owner);
       }
   }

   // src/Attributes/Query/Flag.php
   #[Attribute(Attribute::TARGET_PROPERTY)]
   final class Flag extends Clause
   {
       public function apply(Builder|Relation $builder, string $method, mixed $args, array $parameters = []): void
       {
           if ($args === false) {
               return;
           }

           if ($args === true || $args === null) {
               $builder->{$method}();

               return;
           }

           $builder->{$method}($args);
       }
   }

   // src/Attributes/Query/Terminal.php
   #[Attribute(Attribute::TARGET_PROPERTY)]
   class Terminal
   {
       public function execute(Builder|Relation $builder, string $method, mixed $args): mixed
       {
           return $builder->{$method}($args);
       }
   }

   // src/Attributes/Query/Paginate.php
   #[Attribute(Attribute::TARGET_PROPERTY)]
   final class Paginate extends Terminal
   {
       public function execute(Builder|Relation $builder, string $method, mixed $args): mixed
       {
           if (is_array($args)) {
               return $builder->{$method}(...$args);
           }

           return is_int($args)
               ? $builder->{$method}($args)
               : $builder->{$method}();
       }
   }

   // src/Attributes/Query/Fetch.php
   #[Attribute(Attribute::TARGET_PROPERTY)]
   final class Fetch extends Terminal
   {
       public function execute(Builder|Relation $builder, string $method, mixed $args): mixed
       {
           if (is_array($args)) {
               return $builder->{$method}($args);
           }

           if (is_string($args)) {
               return $builder->{$method}([$args]);
           }

           return $builder->{$method}();
       }
   }

   // src/Attributes/Query/Find.php
   #[Attribute(Attribute::TARGET_PROPERTY)]
   final class Find extends Terminal
   {
       public function execute(Builder|Relation $builder, string $method, mixed $args): mixed
       {
           return is_array($args) && array_is_list($args) && count($args) > 1
               ? $builder->{$method}(...$args)
               : $builder->{$method}($args);
       }
   }

   // src/Attributes/Query/Count.php
   #[Attribute(Attribute::TARGET_PROPERTY)]
   final class Count extends Terminal
   {
       public function execute(Builder|Relation $builder, string $method, mixed $args): mixed
       {
           return is_string($args) ? $builder->count($args) : $builder->count();
       }
   }

   // src/Attributes/Query/Exists.php
   #[Attribute(Attribute::TARGET_PROPERTY)]
   final class Exists extends Terminal
   {
       public function execute(Builder|Relation $builder, string $method, mixed $args): mixed
       {
           return $builder->{$method}();
       }
   }
   ```

2. **`src/Query.php`** (new): DataModel class with one `public const string` + property per key decorated with `#[Key, <Attribute>]`. Reflects terminals dynamically via `self::terminals()` (eliminating manual terminal lists) and executes clauses and terminals through polymorphic attribute dispatch (eliminating switch/match statements):

   ```php
   <?php

   declare(strict_types=1);

   namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Database\Eloquent\Builder;use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\Relation;use InvalidArgumentException;use LogicException;use ReflectionAttribute;use ReflectionClass;use Zerotoprod\DataModel\Describe;use ZeroToProd\LaravelDeclaration\Attributes\BelongsTo;use ZeroToProd\LaravelDeclaration\Attributes\Clause;use ZeroToProd\LaravelDeclaration\Attributes\Count;use ZeroToProd\LaravelDeclaration\Attributes\Exists;use ZeroToProd\LaravelDeclaration\Attributes\Fetch;use ZeroToProd\LaravelDeclaration\Attributes\Find;use ZeroToProd\LaravelDeclaration\Attributes\Flag;use ZeroToProd\LaravelDeclaration\Attributes\Key;use ZeroToProd\LaravelDeclaration\Attributes\Paginate;use ZeroToProd\LaravelDeclaration\Attributes\Spread;use ZeroToProd\LaravelDeclaration\Attributes\Terminal;use ZeroToProd\LaravelDeclaration\Attributes\Where;use ZeroToProd\LaravelDeclaration\Internal\DataModel;

   final readonly class Query
   {
       use DataModel;

       public const string name = 'name';

       #[Key, Describe([Describe::pre => [self::class, 'validate'], Describe::required => true])]
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
           Describe::assign => static fn (mixed $val, array $context): array => array_diff_key(
               $context,
               [self::name => true, self::from => true]
           ),
       ])]
       public array $clauses;

       /** @param  array<array-key, mixed>  $context */
       public static function validate(mixed $value, array $context): void
       {
           $unknown = array_diff(array_keys($context), self::selected(Key::class));

           if ($unknown !== []) {
               throw new LogicException(
                   'The `queries` entry declares unknown key(s): '.implode(', ', $unknown).
                   '. Every key must be an `Illuminate\Database\Eloquent\Builder` method name.'
               );
           }

           if (isset($context['from']) && is_string($context['from'])) {
               $from = $context['from'];
               if (! str_contains($from, '.') && (! class_exists($from) || ! is_subclass_of($from, Model::class))) {
                   throw new LogicException(
                       "The `queries.from` [$from] must be an Eloquent Model class or a `param.relation` string."
                   );
               }
           }

           $declaredTerminals = array_intersect(array_keys($context), array_keys(self::terminals()));
           if (count($declaredTerminals) > 1) {
               throw new LogicException(
                   'The `queries` entry declares multiple terminal execution methods: '.implode(', ', $declaredTerminals).
                   '. A query pipeline must declare at most one terminal method.'
               );
           }
       }

       /** @param  array<string, mixed>  $parameters */
       public function run(array $parameters = []): mixed
       {
           $builder = $this->resolveRoot($parameters);
           $terminal = self::get;
           $terminalArgs = ['*'];

           // Apply clauses dynamically in declared document order via polymorphic attributes:
           foreach ($this->clauses as $method => $args) {
               if (isset(self::terminals()[$method])) {
                   $terminal = $method;
                   $terminalArgs = $args;
                   continue;
               }

               self::clauses()[$method]->apply($builder, $method, $args, $parameters);
           }

           // Terminal execution via terminal attribute:
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

       /** @param  array<string, mixed>  $parameters */
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
                   throw new LogicException("Model [".get_class($model)."] does not define relationship method [$relation].");
               }

               $root = $model->{$relation}();

               if (! $root instanceof Relation && ! $root instanceof Builder) {
                   throw new LogicException(
                       "Method [$relation] on [".get_class($model)."] must return an instance of Illuminate\\Database\\Eloquent\\Relations\\Relation or Illuminate\\Database\\Eloquent\\Builder."
                   );
               }

               return $root;
           }

           /** @var class-string<Model> $class */
           $class = $this->from;

           return $class::query();
       }
   }
   ```

3. **`src/DeclaredQuery.php`** (new): seam invoker in §2.5.

4. **`src/DeclaredView.php`**: update `__invoke` to resolve declared query handles from `$Manifest->queries`.

5. **`src/Manifest.php`**: add the `$queries` property in §2.5.

6. **`manifest.schema.json`**: add `queries` to root properties and definitions:

   ```json
   "queries": {
     "description": "Declared Eloquent query pipelines.",
     "type": "array",
     "items": { "$ref": "#/definitions/query" }
   }
   ```

7. **`composer-require-checker.json`**: whitelist `Illuminate\Database\Eloquent\Builder`, `Illuminate\Contracts\Pagination\LengthAwarePaginator`, `Illuminate\Contracts\Pagination\Paginator`, `Illuminate\Contracts\Pagination\CursorPaginator`.

8. **`README.md`**: add `## Queries` section documenting declared query pipelines and view integration.

9. **Fixtures & Tests**:
   - `tests/Fixtures/manifest/queries.yml`
   - `tests/Feature/DeclaredQueryTest.php`

---

### Sources

1. `Builder` constructor, `where`, `firstWhere`, `latest`, `oldest`, `find`, `findOrFail`, `firstOrFail`, `get`, `paginate`, `simplePaginate`, `cursorPaginate`, `scopes`, `callNamedScope`, `hasNamedScope`, `with`, `without`, `withOnly`, dynamic `__call` forwarding — [Eloquent/Builder.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/Builder.php)
2. `has`, `doesntHave`, `whereRelation`, `orWhereRelation`, `whereDoesntHaveRelation`, `whereBelongsTo`, `withCount`, `withMax`, `withMin`, `withSum`, `withAvg`, `withExists` — [Eloquent/Concerns/QueriesRelationships.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/Concerns/QueriesRelationships.php)
3. `first`, `sole`, `value`, `chunk`, `lazy` — [Database/Concerns/BuildsQueries.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Concerns/BuildsQueries.php)
4. `select`, `addSelect`, `distinct`, `whereIn`, `whereNotIn`, `whereNull`, `whereNotNull`, `whereBetween`, `whereNotBetween`, `whereDate`, `whereMonth`, `whereDay`, `whereYear`, `whereTime`, `whereColumn`, `orderBy`, `orderByDesc`, `inRandomOrder`, `groupBy`, `having`, `limit`, `offset`, `count`, `min`, `max`, `avg`, `sum`, `exists`, `doesntExist`, `pluck` — [Database/Query/Builder.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Query/Builder.php)
5. `Model::query()`, `newQuery()`, `newQueryWithoutScopes()`, `newModelQuery()` — [Eloquent/Model.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/Model.php)
6. `Relation::getQuery()`, `Relation::__call()` forwarding to `Builder` — [Eloquent/Relations/Relation.php](https://github.com/laravel/framework/blob/v13.33.0/src/Illuminate/Database/Eloquent/Relations/Relation.php)
7. Query building, retrieving models, collections, chunks, aggregates, and local scopes — [docs/repos/laravel/docs/eloquent.md](repos/laravel/docs/eloquent.md)
8. Running queries, basic wheres, compound wheres, selects, ordering, grouping, and limit/offset — [docs/repos/laravel/docs/queries.md](repos/laravel/docs/queries.md)
9. Basic pagination, simple pagination, cursor pagination, and URL path resolution — [docs/repos/laravel/docs/pagination.md](repos/laravel/docs/pagination.md)
10. Querying relationships, relationship existence, relationship counts, and eager loading — [docs/repos/laravel/docs/eloquent-relationships.md](repos/laravel/docs/eloquent-relationships.md)
11. Phase 4 query pipeline roadmap rule and gating question — [docs/declarative-request-to-view-roadmap.md](declarative-request-to-view-roadmap.md)
12. Schema validation, `DataModel` property mapping, and error handling — [docs/declarative-model.md](declarative-model.md)
