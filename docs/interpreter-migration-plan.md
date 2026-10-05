# Interpreter Migration Plan

Moves this package onto the general-purpose interpreter specified in [manifest-interpreter.md](manifest-interpreter.md).
After the migration the repository holds two things: a pure-PHP **interpreter** in `interpreter/` with no
dependencies, and a Laravel **host** in `src/` that owns the root receiver, the value vocabulary (**ρ**), the data
keys, and the migrate command's idempotence. The **schema**, its generator, its validator and every `x-manifest`
curation keyword are deleted.

Every code block below is complete and drop-in; file paths are exact. No code is implemented by this document.

| | Before (`5f32c2a`) | After |
| --- | --- | --- |
| Signature source | `manifest.schema.json` (`x-manifest.params`) | `ReflectionMethod` on the receiver at dispatch |
| Forms | `Forms::calls()` + `Forms::schema()`, 10 rows, 6 curation keywords | `Interpreter::calls()`, 9 rows, no curation |
| Value hook | `Resolve` reading `x-manifest.resolve` | `Resolve` (ρ) reading `UNTYPED` host data + declared types |
| Data keys | `x-manifest.data` skipped inside `Engine::body()` | `ManifestStore::body()` strips them before dispatch |
| Static receiver | root FQCN "projected with a static key" | any key containing `\` |
| Migrate guard | `Engine::withGuard()` + `Guards` table | `GuardedBlueprint` installed via `Builder::blueprintResolver()` |
| Validation | `laravel-declaration:validate`, JSON Schema | none: PHP's own errors |
| Dependencies | `justinrainbow/json-schema`, `nikic/php-parser` | removed |

---

## 0. File inventory

**Add**

| path | role |
| --- | --- |
| `interpreter/Interpreter.php` | the interpreter (§1) |
| `src/Internal/Resolve.php` | ρ for Laravel (§2) |
| `src/Internal/GuardedBlueprint.php`, `src/Internal/Tally.php` | migrate idempotence as a receiver (§4) |
| `tests/Interpreter/InterpreterTest.php`, `tests/Fixtures/Interpreter/Recorder.php`, `tests/Fixtures/Interpreter/Statics.php` | the interpreter's own suite (§7) |
| `bin/interpreter-check.sh` | purity guard (§5.4) |

**Modify**

| path | change |
| --- | --- |
| `src/Providers/ManifestServiceProvider.php` | construct the interpreter through ρ; apply `ManifestStore::body()` (§3) |
| `src/Internal/ManifestStore.php` | `DATA` constant and `body()` (§3) |
| `src/Internal/Commands/MigrateCommand.php` | drive the real `Builder` with the guard installed (§4) |
| `src/LaravelDeclarationProvider.php` | drop `ValidateCommand`, `GenerateManifestSchemaCommand` (§5) |
| `src/Internal/Installer.php` | drop the `yaml-language-server` line; `make:` skeleton as a row (§5, §6) |
| `composer.json`, `composer-require-checker.json`, `bin/require-check.sh`, `phpstan.neon`, `CLAUDE.md`, `README.md` | §5 |
| manifests in `tests/`, `README.md` | shapes in §6 |

**Delete**

```
src/Internal/Engine/Engine.php
src/Internal/Engine/Forms.php
src/Internal/Engine/Signature.php
src/Internal/Engine/Guards.php
src/Internal/Engine/Resolve.php            (replaced by src/Internal/Resolve.php)
src/Internal/SchemaGenerator.php
src/Internal/Commands/GenerateManifestSchemaCommand.php
src/Internal/Commands/ValidateCommand.php
manifest.schema.json
tests/Feature/Engine/EngineTest.php
tests/Feature/Engine/FormsTest.php
tests/Feature/Engine/GenerateManifestSchemaCommandTest.php
tests/Feature/Engine/GuardsTest.php
tests/Feature/Engine/SchemaGeneratorTest.php
tests/Feature/Engine/SignatureParityTest.php
tests/Feature/Engine/Support.php
tests/Feature/ValidateCommandTest.php
tests/Fixtures/SchemaGenerator/            (whole directory)
docs/declarative-manifest-schema-generator.md
docs/generate-manifest-schema-plan.md
docs/implementation/schema_generator/      (whole directory)
```

`tests/Feature/Engine/ResolveTest.php` and `ManifestStoreTest.php` move to `tests/Feature/` and are rewritten (§7.3).

---

## 1. `interpreter/Interpreter.php`

The whole interpreter. It uses `ReflectionMethod`, `method_exists`, `array_is_list`, `array_key_first`,
`array_find`, `array_all`, `array_any`, `str_contains` — all PHP 8.4.

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\Manifest;

use Closure;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

/**
 * Reads a manifest the way PHP reads a call site: keys are method names, values are arguments, nesting is
 * composition. The only metadata is reflection on the receiver; the only seam is ρ. Nothing is validated and
 * nothing is caught — PHP's own errors say what went wrong. Specification: docs/manifest-interpreter.md.
 */
final class Interpreter
{
    /** @var Closure(mixed, ?ReflectionParameter): mixed */
    private readonly Closure $resolve;

    /** @param  null|Closure(mixed, ?ReflectionParameter): mixed  $resolve  ρ — identity when null */
    public function __construct(?Closure $resolve = null)
    {
        $this->resolve = $resolve ?? static fn (mixed $value): mixed => $value;
    }

    /**
     * `$t->a(); $t->b();` — a body on a receiver, in manifest order. An object dispatches with `->`, a class-string
     * with `::`, `null` is a body with no receiver (only class-name keys are meaningful).
     *
     * @param  array<array-key, mixed>  $body
     */
    public function body(object|string|null $receiver, array $body): void
    {
        foreach ($body as $key => $value) {
            $key = (string) $key;

            if (str_contains($key, '\\')) {                                                   // a fully qualified class name: a static receiver
                $this->body($key, $value);                                                    // @phpstan-ignore argument.type (a non-array value is PHP's TypeError)

                continue;
            }

            foreach ($this->calls($this->parameters($receiver, $key), $value) as [$arguments, $rest]) {
                $this->chain(
                    is_string($receiver) ? $receiver::{$key}(...$arguments) : $receiver->{$key}(...$arguments), // @phpstan-ignore method.nonObject (null is PHP's Error)
                    $rest,
                );
            }
        }
    }

    /**
     * λ — a body as a PHP closure. Public so a host's ρ can produce one for a parameter PHP leaves untyped.
     *
     * @param  array<array-key, mixed>  $body
     */
    public function closure(array $body): Closure
    {
        return function (object $receiver) use ($body): void {
            $this->body($receiver, $body);
        };
    }

    /**
     * `$r->a()->b()` — the rest of a row is a fluent expression: each key is called on the previous key's return.
     *
     * @param  array<string, mixed>  $rest
     */
    private function chain(mixed $receiver, array $rest): void
    {
        foreach ($rest as $key => $value) {
            foreach ($this->calls($this->parameters($receiver, $key), $value) as [$arguments, $deeper]) {
                $next = $receiver->{$key}(...$arguments);                                     // @phpstan-ignore method.nonObject (a non-object receiver is PHP's Error)

                $this->chain($next, $deeper);

                $receiver = $next;
            }
        }
    }

    /**
     * Σ — the receiver's signature for the key, or null for a method PHP cannot reflect (a `__call` surface),
     * which PHP itself receives as `(mixed ...$arguments)`.
     *
     * @return list<ReflectionParameter>|null
     */
    private function parameters(mixed $receiver, string $method): ?array
    {
        return (is_object($receiver) || is_string($receiver)) && method_exists($receiver, $method)
            ? new ReflectionMethod($receiver, $method)->getParameters()
            : null;
    }

    /**
     * The forms — one value, the calls it denotes. First match wins.
     *
     * @param  list<ReflectionParameter>|null  $parameters
     * @return list<array{0: array<array-key, mixed>, 1: array<string, mixed>}>
     */
    private function calls(?array $parameters, mixed $value): array
    {
        $p0 = $parameters[0] ?? null;
        $p1 = $parameters[1] ?? null;
        $names = array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $parameters ?? []);

        if ($value === null || $value === true) {                                              // 1  m()
            return [[[], []]];
        }

        if (! is_array($value)) {                                                               // 2  m($x)
            return [[[$this->argument($value, $p0)], []]];
        }

        if (array_is_list($value)) {
            if ($value !== [] && array_all($value, static fn (mixed $item): bool => self::isRow($item, $names))) {
                return array_map(fn (mixed $item): array => $this->row((array) $item, $parameters ?? []), $value); // 3  one call per row
            }

            if ($parameters === null || ($p0?->isVariadic() ?? false)) {                         // 4  m(...$xs)
                return [[array_map(fn (mixed $item): mixed => $this->argument($item, $p0), $value), []]];
            }

            if (self::typed($p0, 'array', 'iterable')) {                                        // 5  m([...]) — the list is the argument
                return [[[$this->argument($value, $p0)], []]];
            }

            $calls = [];                                                                        // 6  one call per item

            foreach ($value as $item) {
                $calls = [...$calls, ...(self::isMap($item) ? $this->calls($parameters, $item) : [[[$this->argument($item, $p0)], []]])];
            }

            return $calls;
        }

        if (self::isRow($value, $names)) {                                                      // 8  m(a: …, b: …)->rest
            return [$this->row($value, $parameters ?? [])];
        }

        if (count($names) >= 2 && ! self::typed($p0, 'array', 'iterable')) {                    // 7  foreach ($v as $k => $e) m($k, $e)
            $calls = [];

            foreach ($value as $key => $entry) {
                if (is_array($entry) && array_is_list($entry) && ! self::typed($p1, 'array', 'iterable')) {
                    foreach ($entry as $item) {
                        $calls[] = [[$key, $this->argument($item, $p1)], []];
                    }

                    continue;
                }

                $calls[] = [[$key, $this->argument($entry, $p1)], []];
            }

            return $calls;
        }

        return [[[$this->argument($value, $p0)], []]];                                          // 9  m([k => v]) — the map is the argument
    }

    /**
     * A row: the keys that are parameter names become named arguments; the others ride the return value.
     *
     * @param  array<array-key, mixed>  $item
     * @param  list<ReflectionParameter>  $parameters
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function row(array $item, array $parameters): array
    {
        $named = [];
        $rest = [];

        foreach ($item as $key => $value) {
            $key = (string) $key;
            $parameter = array_find($parameters, static fn (ReflectionParameter $parameter): bool => $parameter->getName() === $key);

            if ($parameter === null) {
                $rest[$key] = $value;
            } else {
                $named[$key] = $this->argument($value, $parameter);
            }
        }

        return [$named, $rest];
    }

    /** arg(x, p) — a map under a parameter that accepts a Closure is λ; everything else is ρ's. */
    private function argument(mixed $value, ?ReflectionParameter $parameter): mixed
    {
        return self::isMap($value) && self::typed($parameter, Closure::class, 'callable')
            ? $this->closure($value)
            : ($this->resolve)($value, $parameter);
    }

    /** @param  list<string>  $names */
    private static function isRow(mixed $value, array $names): bool
    {
        return self::isMap($value) && in_array((string) array_key_first($value), $names, true);
    }

    /** @phpstan-assert-if-true array<array-key, mixed> $value */
    private static function isMap(mixed $value): bool
    {
        return is_array($value) && ! array_is_list($value);
    }

    /** Whether the declared type is, or is a union containing, one of the named types. Untyped and `mixed` are neither. */
    private static function typed(?ReflectionParameter $parameter, string ...$names): bool
    {
        $type = $parameter?->getType();
        $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];

        return array_any($types, static fn (mixed $type): bool => $type instanceof ReflectionNamedType && in_array($type->getName(), $names, true));
    }
}
```

Reading guide, spec row → code: 1–2 scalars; 3 rows-in-a-list (`isRows` from the old `Forms`); 4 spread, which also
covers a `__call` surface (`$parameters === null`); 5 the array-typed argument (replaces `list: argument`); 6 fan-out
with map items recursing into 7–9; 7 entries (replaces `form: entries` for `afterResolving`-style keys); 8 the row
with chain; 9 the map argument. `argument()` is the only place λ is decided; ρ sees everything else.

---

## 2. `src/Internal/Resolve.php` — ρ for Laravel

The former `Internal\Engine\Resolve` with its schema input replaced by two things PHP gives it: the parameter's
declared type and the parameter's method and name. The `UNTYPED` table is the whole of the former curation
(`closure: [...]`, `resolve: {...}`) transcribed from `manifest.schema.json` as it stands at `5f32c2a`.

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal;

use Closure;
use Illuminate\Contracts\Container\Container;
use LogicException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use ZeroToProd\Manifest\Interpreter;

/**
 * ρ — the only place a Laravel opinion about a manifest value lives. A parameter PHP declares as Closure/callable
 * resolves references to closures; the parameters Laravel leaves untyped are named in UNTYPED; everything else
 * reaches Laravel untouched.
 *
 * @internal
 */
final class Resolve
{
    /**
     * Vocabularies for parameters whose declared type says nothing: method → parameter → vocabulary.
     *
     *   closure   `Class@method` | `Class::method` | invokable `Class` | `function` → a container-called Closure;
     *             `*.php` → the file's Closure; a map → λ (a body on the closure's first argument)
     *   phpFile   `*.php` → the file's return value (any type); anything else untouched
     *   concrete  `*.php` → phpFile; `~` and class-strings untouched
     *   path      a relative path → under `path.base`; an absolute path untouched
     */
    private const array UNTYPED = [
        'registered' => ['callback' => 'closure'],
        'booting' => ['callback' => 'closure'],
        'booted' => ['callback' => 'closure'],
        'terminating' => ['callback' => 'closure'],
        'group' => ['routes' => 'closure'],                                  // Router::group(array $attributes, $routes)
        'missing' => ['missing' => 'closure', 'callback' => 'closure'],     // Route::missing($missing); PendingResourceRegistration::missing($callback)
        'whenRequestLifecycleIsLongerThan' => ['handler' => 'closure'],
        'macro' => ['macro' => 'closure'],
        'bind' => ['abstract' => 'concrete', 'concrete' => 'concrete'],
        'bindIf' => ['abstract' => 'concrete', 'concrete' => 'concrete'],
        'singleton' => ['abstract' => 'concrete', 'concrete' => 'concrete'],
        'singletonIf' => ['abstract' => 'concrete', 'concrete' => 'concrete'],
        'scoped' => ['abstract' => 'concrete', 'concrete' => 'concrete'],
        'scopedIf' => ['abstract' => 'concrete', 'concrete' => 'concrete'],
        'instance' => ['instance' => 'phpFile'],
        'give' => ['implementation' => 'concrete'],
        'useAppPath' => ['path' => 'path'],
        'useBootstrapPath' => ['path' => 'path'],
        'useConfigPath' => ['path' => 'path'],
        'useDatabasePath' => ['path' => 'path'],
        'useLangPath' => ['path' => 'path'],
        'usePublicPath' => ['path' => 'path'],
        'useStoragePath' => ['path' => 'path'],
        'useEnvironmentPath' => ['path' => 'path'],
        'addLocation' => ['location' => 'path'],
        'prependLocation' => ['location' => 'path'],
        'addNamespace' => ['hints' => 'path'],
        'prependNamespace' => ['hints' => 'path'],
        'replaceNamespace' => ['hints' => 'path'],
        'anonymousComponentPath' => ['path' => 'path'],
        'anonymousComponentNamespace' => ['directory' => 'path'],
    ];

    /** @var array<string, mixed> every `.php` reference is required once per process */
    private static array $files = [];

    private Interpreter $interpreter;

    private function __construct(private readonly Container $container) {}

    /** The interpreter wired to this ρ — the host's one construction site. */
    public static function interpreter(Container $container): Interpreter
    {
        $resolve = new self($container);

        return $resolve->interpreter = new Interpreter($resolve(...));
    }

    public function __invoke(mixed $value, ?ReflectionParameter $parameter): mixed
    {
        if ($parameter === null) {
            return $value;                                                                        // a __call surface: PHP decides
        }

        $vocabulary = self::UNTYPED[$parameter->getDeclaringFunction()->getName()][$parameter->getName()]
            ?? (self::acceptsClosure($parameter) ? 'closure' : null);

        return match ($vocabulary) {
            null => $value,
            'closure' => is_array($value) && ! array_is_list($value) ? $this->interpreter->closure($value) : $this->closure($value),
            'phpFile', 'concrete' => $this->phpFile($value),
            'path' => is_string($value) ? $this->path($value) : $value,
        };
    }

    private static function acceptsClosure(ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();
        $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];

        return array_any($types, static fn (mixed $type): bool => $type instanceof ReflectionNamedType && in_array($type->getName(), [Closure::class, 'callable'], true));
    }

    /**
     * `Class@method` | `Class::method` | invokable `Class` | `function` → a Closure that pairs its positional
     * arguments with the target's parameter names and lets the container inject the rest; `*.php` → the file's Closure.
     */
    private function closure(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        if (str_ends_with($value, '.php')) {
            $closure = $this->phpFile($value);

            return $closure instanceof Closure
                ? $closure
                : throw new LogicException("The reference [$value] must return a Closure, ".get_debug_type($closure).' returned.');
        }

        $container = $this->container;
        $reflect = $this->reflect(...);                                                          // bound here: Macroable rebinds the wrapper's scope to the macro host

        return static function (mixed ...$arguments) use ($container, $reflect, $value): mixed {
            $named = [];

            foreach ($reflect($value)->getParameters() as $position => $parameter) {
                if ($parameter->isVariadic()) {
                    break;
                }

                if (array_key_exists($position, $arguments)) {
                    $named[$parameter->getName()] = $arguments[$position];

                    unset($arguments[$position]);
                }
            }

            return $container->call($value, [...$named, ...array_values($arguments)]);
        };
    }

    private function reflect(string $reference): ReflectionFunctionAbstract
    {
        if (str_contains($reference, '@')) {
            [$class, $method] = explode('@', $reference, 2);

            return new ReflectionMethod($class, $method);
        }

        if (str_contains($reference, '::')) {
            return ReflectionMethod::createFromMethodName($reference);
        }

        return class_exists($reference) ? new ReflectionMethod($reference, '__invoke') : new ReflectionFunction($reference);
    }

    /** `*.php` → required once, its return value (any type); anything else untouched. */
    private function phpFile(mixed $value): mixed
    {
        if (! is_string($value) || ! str_ends_with($value, '.php')) {
            return $value;
        }

        $path = $this->path($value);

        if (! array_key_exists($path, self::$files)) {
            self::$files[$path] = require $path;
        }

        return self::$files[$path];
    }

    /** A relative path resolves under the base path; one starting with `/` or `\` is used as-is. */
    private function path(string $value): string
    {
        if (str_starts_with($value, '/') || str_starts_with($value, '\\')) {
            return $value;
        }

        $base = $this->container->make('path.base');

        return (is_string($base) ? $base : '').'/'.$value;
    }
}
```

Why the table is keyed by method name only: `ReflectionParameter::getDeclaringFunction()` reports `Container::bind`
for a key written against the `Application`, and trait methods report the using class — keying by class would
need the same walk the old `Engine::def()` did. No two entries collide on `(method, parameter)` across the
projected classes (`Router::bind($key, $binder)` has different parameter names from `Container::bind`).

---

## 3. Provider and store

### 3.1 `src/Internal/ManifestStore.php`

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal;

/**
 * The raw manifest as YAML decoded it. The data keys are read from here by the seams and by `declaration:migrate`;
 * `body()` is what the interpreter applies to the application.
 *
 * @internal
 */
final readonly class ManifestStore
{
    /** Root keys the host reads itself; the interpreter never sees them. */
    public const array DATA = ['requests', 'models', 'queries', 'schema', 'extra'];

    /** @param  array<string, mixed>  $manifest */
    public function __construct(private array $manifest = []) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->manifest;
    }

    /** @return array<string, mixed> the manifest minus the data keys: a body on Illuminate\Foundation\Application */
    public function body(): array
    {
        return array_diff_key($this->manifest, array_flip(self::DATA));
    }

    public function block(string $block): mixed
    {
        return $this->manifest[$block] ?? null;
    }

    /**
     * The list items of a block, keyed by the value of one of their fields (`requests` by `name`, `models` by `class`).
     *
     * @return array<string, array<string, mixed>>
     */
    public function items(string $block, string $field): array
    {
        $items = [];
        $block = $this->block($block);

        foreach (is_array($block) ? $block : [] as $item) {
            $key = is_array($item) ? ($item[$field] ?? null) : null;

            if (is_array($item) && is_string($key)) {
                /** @var array<string, mixed> $item */
                $items[$key] = $item;
            }
        }

        return $items;
    }

    /** @return array<string, mixed>|null */
    public function item(string $block, string $field, string $value): ?array
    {
        return $this->items($block, $field)[$value] ?? null;
    }
}
```

### 3.2 `src/Providers/ManifestServiceProvider.php`

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Yaml\Yaml;
use ZeroToProd\LaravelDeclaration\Internal\ManifestStore;
use ZeroToProd\LaravelDeclaration\Internal\Resolve;
use ZeroToProd\Manifest\Interpreter;

/**
 * The only dispatch call site in the package: the manifest is a body on the application, applied in `register()`.
 * Timing is written in the manifest with the application's own lifecycle methods (`registered`, `booting`,
 * `booted`, `afterResolving`, `make`), so this provider has no `boot()`.
 *
 * @internal
 */
class ManifestServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $store = new ManifestStore($this->manifest(Config::string('laravel-declaration.manifest', 'manifest/app.yml')));

        $this->app->instance(ManifestStore::class, $store);

        $interpreter = Resolve::interpreter($this->app);

        $this->app->instance(Interpreter::class, $interpreter);

        $interpreter->body($this->app, $store->body());
    }

    /** @return array<string, mixed> */
    private function manifest(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }

        $manifest = Yaml::parseFile($file);

        return is_array($manifest) ? $manifest : [];
    }
}
```

---

## 4. The migrate command

The old `Guards` intercepted every engine call. The interpreter has no interception, and a decorator would hide the
real signatures from reflection. The policy therefore lives in the one object Laravel lets the host substitute
without changing a signature: the **Blueprint**, through `Builder::blueprintResolver()`
(`vendor/laravel/framework/src/Illuminate/Database/Schema/Builder.php:714,792`). `Builder::create/table/rename/drop/dropIfExists`
all construct their blueprint through that resolver and finish with `$blueprint->build()`; overriding `build()`
decides a table-level verb whole and prunes an alter body column by column against the live schema.

Because `Blueprint::addColumnDefinition()` pushes a `ColumnDefinition` into **both** `$columns` and `$commands`
when not creating, pruning filters `$commands` once and keeps `$columns` consistent by identity.

### 4.1 `src/Internal/Tally.php`

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal;

use Closure;

/**
 * What the migrate command reports: shared by every GuardedBlueprint the resolver creates.
 *
 * @internal
 */
final class Tally
{
    public int $created = 0;

    /** @var array<string, int> */
    public array $altered = [];

    /** @var array<string, true> */
    public array $missing = [];

    /** @param  Closure(string, string): void  $report  the two-column console detail */
    public function __construct(public readonly Closure $report) {}
}
```

### 4.2 `src/Internal/GuardedBlueprint.php`

```php
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

        $verb = array_find($this->commands, static fn (Fluent $command): bool => ! $command instanceof ColumnDefinition && in_array($command->name, ['drop', 'dropIfExists', 'rename'], true));

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
    private function table(string $table, Fluent $verb): bool
    {
        switch ($verb->name) {
            case 'rename':
                $to = (string) $verb->to;
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
        $present = $this->builder->hasColumn($this->getTable(), (string) $column->name);

        return $column->change === true ? $present : ! $present;
    }

    /** A command is kept when the live schema says it would succeed. Blueprint has already resolved index names. */
    private function command(Fluent $command): bool
    {
        $table = $this->getTable();
        $name = (string) $command->name;

        return match (true) {
            $name === 'dropColumn' => $this->builder->hasColumns($table, array_map(strval(...), (array) $command->columns)),
            $name === 'renameColumn' => $this->builder->hasColumn($table, (string) $command->from) && ! $this->builder->hasColumn($table, (string) $command->to),
            $name === 'renameIndex' => $this->builder->hasIndex($table, (string) $command->from) && ! $this->builder->hasIndex($table, (string) $command->to),
            $name === 'dropForeign' => $this->builder->hasForeignKey($table, $command->index),
            str_starts_with($name, 'drop') => $this->builder->hasIndex($table, $command->index),      // dropIndex, dropUnique, dropPrimary, dropFullText, dropSpatialIndex, dropVectorIndex
            $name === 'foreign' => ! $this->builder->hasForeignKey($table, $command->columns),
            in_array($name, self::INDEXES, true) => ! $this->builder->hasIndex($table, $command->index),
            default => true,                                                                     // engine, charset, collation, comment, …
        };
    }
}
```

The canonical-column table of the old `Guards` (`timestamps → created_at`, `rememberToken → remember_token`, …) is
gone: by the time `build()` runs, `timestamps()` *is* two `ColumnDefinition`s named `created_at` and `updated_at`,
and `dropTimestamps()` *is* a `dropColumn` command with those columns.

### 4.3 `src/Internal/Commands/MigrateCommand.php`

```php
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
```

How the schema fixture reads now, with no schema in the loop (`tests/Fixtures/manifest/schema-alter.yml`):

| manifest | interpreter | guard |
| --- | --- | --- |
| `dropIfExists: [scratch]` | `dropIfExists($table)` untyped → rule 6 → `dropIfExists('scratch')` | blueprint `dropIfExists` command → reported, runs |
| `rename: {old_users: users}` | `rename($from, $to)` → rule 7 → `rename('old_users', 'users')` | `rename` command → runs iff `old_users` exists and `users` does not |
| `create: {people: {id: ~, …}}` | `create($table, Closure $callback)` → rule 7, `p1` is Closure → `create('people', λ)` | `creating()` → skipped when the table exists |
| `table: {people: {string: [{column: nickname, length: 64}, {column: title, length: 200, change: ~}]}}` | `table('people', λ)`; in λ: rule 3 → `string(column: 'nickname', length: 64)`, `string(column: 'title', length: 200)->change()` | `nickname` kept iff absent; `title` kept iff present (`change === true`) |
| `index: [{columns: [owner_id, priority], nullsNotDistinct: ~}]` | `index($columns, …)` → rule 3 → `index(columns: [...])->nullsNotDistinct()` | `index` command kept iff `hasIndex` is false for the resolved name |

---

## 5. Removing the schema stack

### 5.1 `src/LaravelDeclarationProvider.php`

```php
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                MigrateCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/laravel-declaration.php' => config_path('laravel-declaration.php'),
            ], 'laravel-declaration-config');
        }

        if (! $this->app->environment('production')) {
            $this->registerMcpServer();
        }
    }
```

Remove the two `use` lines for `GenerateManifestSchemaCommand` and `ValidateCommand`.

### 5.2 `composer.json`

```diff
     "require": {
         "php": "^8.4",
         "illuminate/console": "^13.0",
-        "illuminate/support": "^13.0",
-        "justinrainbow/json-schema": "^6.13",
-        "nikic/php-parser": "^5.9"
+        "illuminate/support": "^13.0"
     },
@@
         "autoload-dev": {
             "files": [
-                "tests/Fixtures/App/Requests/functions.php",
-                "tests/Fixtures/Engine/functions.php"
+                "tests/Fixtures/App/Requests/functions.php",
+                "tests/Fixtures/Interpreter/functions.php"
             ]
         },
@@
         "check": [
             "@lint",
             "@rector-lint",
             "@analyse",
             "@coverage",
-            "@schema-check"
+            "@interpreter-check"
         ],
@@
-        "schema": "testbench declaration:generate-manifest-schema",
-        "schema-check": "testbench declaration:generate-manifest-schema --check"
+        "interpreter-check": "bin/interpreter-check.sh"
```

`illuminate/support` already pulls `symfony/yaml`; `ReflectionMethod` replaces `nikic/php-parser`; nothing validates, so
`justinrainbow/json-schema` goes. Run `composer update --lock` after editing.

### 5.3 `composer-require-checker.json`

Drop `"Composer\\Autoload\\ClassLoader"` (only the generator used it). Add `"Illuminate\\Database\\Connection"`,
`"Illuminate\\Database\\Schema\\ColumnDefinition"` and `"Illuminate\\Support\\Fluent"` for `GuardedBlueprint`.
`bin/require-check.sh` copies `src` into the tree it checks; add `interpreter`:

```diff
 cp composer.json composer-require-checker.json "$TREE/"
-cp -R src "$TREE/"
+cp -R src interpreter "$TREE/"
```

### 5.4 `bin/interpreter-check.sh` (new, `chmod +x`)

The interpreter must not import anything but PHP. The require-checker cannot say that per directory, so:

```sh
#!/usr/bin/env sh

set -e

if grep -rEn '^use (Illuminate|Symfony|Laravel|Composer|ZeroToProd\\LaravelDeclaration)\\' interpreter; then
    echo "interpreter-check: interpreter/ imports a non-PHP symbol" >&2
    exit 1
fi

echo "interpreter-check: interpreter/ is pure PHP"
```

### 5.5 `phpstan.neon`

```diff
     paths:
+        - interpreter
         - src
         - tests
```

### 5.6 `CLAUDE.md`

```diff
 composer check # Does not mutate
 composer fix # Mutates artifacts
-composer schema # Regenerates manifest.schema.json from x-manifest.classes (never edit it by hand)
 composer mcp list # tool names and descriptions
```

### 5.7 `README.md`

- Line 3: `A general-purpose manifest engine for Laravel: one YAML body on \`Illuminate\Foundation\Application\`, read by a pure-PHP interpreter against the framework's own method signatures.`
- Delete the `composer schema` / `declaration:generate-manifest-schema` block (lines 199–210) and the
  `laravel-declaration:validate` block (lines 246–250).
- Rewrite "How a key is read" (lines 252–274) from [manifest-interpreter.md §4.3](manifest-interpreter.md); the
  vocabulary paragraph becomes a description of `Resolve::UNTYPED`.
- Shapes: §6 below.

### 5.8 `src/Internal/Installer.php`

```php
    public static function manifest(): string
    {
        return <<<'YAML'
            # The manifest is a body on Illuminate\Foundation\Application: every root key is one of its
            # methods, applied in order when the package registers. Timing is written with the
            # application's own lifecycle methods.

            make:                                   # a service already resolved at register(): make(abstract: …)->set(…)
              abstract: Illuminate\Config\Repository
              set: {}                               # app.name: Tenant Console

            registered:                             # a body on the application once every provider has registered
              bind: {}                              # App\Contracts\Pdf: App\Services\DomPdf
              singleton: []                         # - App\Services\TenantContext

            register: []                            # - App\Providers\AppServiceProvider

            afterResolving:                         # a body on each service when it first resolves
              Illuminate\Routing\Router:
                addRoute: []                        # - {methods: GET, uri: /, action: App\Http\HomeController, name: home}

            YAML;
    }
```

`InstallCommandTest` and `InstallToolTest` assert this skeleton; update their expected strings in step.

---

## 6. Manifest shapes that change

Every former curation keyword is gone. The manifest says the same call with the forms the interpreter has.

### 6.1 `form: entries` → a row whose rest chains

`Application::make($abstract, array $parameters = [])` has an array-typed second parameter, so
`make: {FQCN: {…}}` would now read as `make(FQCN, ['set' => …])` — rule 7 puts the map into `$parameters`. Write
the row:

```yaml
# before
make:
  Illuminate\Config\Repository:
    set:
      app.name: Tenant Console
      cache.stores.redis.connection: cache

# after — make(abstract: …) then set(…) per entry on the made repository
make:
  abstract: Illuminate\Config\Repository
  set:
    app.name: Tenant Console
    cache.stores.redis.connection: cache
```

Several services, or several methods on a service whose methods return `void` (`Repository::set` does), are a
**list of rows** — one `make` per expression, exactly as the PHP would be written:

```yaml
make:
  - abstract: Illuminate\Config\Repository
    set: {app.name: Tenant Console}
  - abstract: Illuminate\Config\Repository
    push: {app.providers: App\Providers\ReportProvider}
  - abstract: db
    setDefaultConnection: reporting
```

`Container::when($concrete)` the same way:

```yaml
# before
when:
  App\Http\Controllers\PhotoController:
    needs: App\Contracts\Filesystem
    give: App\Services\LocalFs

# after — when(concrete: …)->needs(…)->give(…)
when:
  concrete: App\Http\Controllers\PhotoController
  needs: App\Contracts\Filesystem
  give: App\Services\LocalFs
```

Sites: `tests/Acceptance/ConfigTest.php` (13 `make:`), `tests/Feature/ConfigRegistrationTest.php` (7),
`tests/Feature/DatabaseRegistrationTest.php:13`, `tests/Feature/ViewRegistrationTest.php:56,72,87`,
`tests/Acceptance/ContainerServicesTest.php:76,97,112` (`when:`), `tests/Feature/ApplicationRegistrationTest.php:240`,
`README.md:230,283,320,336,534,827`, `Installer::manifest()`. Not affected: `factory: {make: …}` in
`DeclaredViewFactoryTest` (a seam key, not the engine) and `when:` in `tests/Fixtures/manifest/validator.yml:54`
(`Rule::when` inside the `requests` data key).

### 6.2 `list: argument` → a row naming the parameter

`PendingResourceRegistration::only($methods)` and friends are untyped; a bare list now fans out (`only('index')`,
`only('show')` — last wins). Name the parameter:

```yaml
# before
resource:
  - name: photos
    controller: App\Http\Controllers\PhotoController
    only: [index, show]

# after
resource:
  - name: photos
    controller: App\Http\Controllers\PhotoController
    only: {methods: [index, show]}
```

Same for `except`, `middleware`, `withoutMiddleware` on the two pending-registration classes and for
`ContextualBindingBuilder::give($implementation)` with a list (`give: {implementation: [App\A, App\B]}`).
Sites: `tests/Fixtures/manifest/route-registrars.yml:79,93`, `tests/Feature/RouteRegistrationTest.php:290`,
`tests/Acceptance/ContainerServicesTest.php:115`, `README.md:438`.

### 6.3 `order: reverse` → write the list in call order

`prependMiddleware`, `prependMiddlewareToGroup`, `prependToMiddlewarePriority`, `addToMiddlewarePriorityAfter`
(Kernel and Router) were reversed so manifest order equalled final order. Now each item is one call in manifest
order, as PHP would make them; the author writes the calls:

```yaml
# before — final order: First, Second
prependMiddlewareToGroup:
  tenant: [GroupPrependedFirst, GroupPrependedSecond]

# after — the same final order
prependMiddlewareToGroup:
  tenant: [GroupPrependedSecond, GroupPrependedFirst]
```

Sites: `tests/Fixtures/manifest/kernel.yml:5,17,25,34`, `tests/Fixtures/manifest/router.yml:18`,
`tests/Acceptance/KernelTest.php:88,319,465`, `tests/Feature/KernelRegistrationTest.php:165,169`, and the
expectations in `tests/Feature/RouterMiddlewarePrecedenceTest.php`.

### 6.4 Unchanged shapes, now read by reflection

| manifest | reading |
| --- | --- |
| `registered: {bind: {…}}` | `registered($callback)` untyped → rule 9 → ρ `UNTYPED` → λ |
| `booting: [App\Hooks\WarmConnections]` | rule 6 → ρ `closure` → container-called Closure |
| `afterResolving: {Illuminate\Routing\Router: {…}}` | `afterResolving($abstract, ?Closure $callback)` → rule 7, `p1` Closure → λ |
| `extend: {cache.store: app/extensions/store-cache.php}` | `extend($abstract, Closure $closure)` → ρ by declared type → the file's Closure |
| `instance: {app.rate_limiter: app/instances/limiter.php}` | ρ `UNTYPED` → `phpFile` |
| `bind: {App\Contracts\Pdf: App\Services\DomPdf}` | rule 7 → `bind('App\Contracts\Pdf', 'App\Services\DomPdf')` |
| `addRoute: [{methods: GET, uri: …, action: …, name: home, where: {user: '[0-9]+'}, middleware: [web]}]` | rule 3 → `addRoute(methods: …, uri: …, action: …)->name('home')->where('user', '[0-9]+')->middleware('web')` |
| `Illuminate\Pagination\Paginator: {useBootstrapFive: ~}` | a `\` key → `Paginator::useBootstrapFive()` |
| `schema: …`, `requests: …`, `models: …`, `queries: …`, `extra: …` | stripped by `ManifestStore::body()` |

---

## 7. Tests

### 7.1 `tests/Fixtures/Interpreter/Recorder.php`

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\Interpreter;

use Closure;

/** Records every call as [method, arguments]; the methods cover each form the interpreter reads. */
final class Recorder
{
    /** @var list<array{0: string, 1: array<array-key, mixed>}> */
    public array $calls = [];

    public function none(): void
    {
        $this->calls[] = ['none', []];
    }

    public function zero(): void
    {
        $this->calls[] = ['zero', []];
    }

    public function one($x): void
    {
        $this->calls[] = ['one', [$x]];
    }

    public function typed(array $x): void
    {
        $this->calls[] = ['typed', [$x]];
    }

    public function spread(...$x): void
    {
        $this->calls[] = ['spread', $x];
    }

    public function pair($k, $v): void
    {
        $this->calls[] = ['pair', [$k, $v]];
    }

    public function pairArray($k, array $v): void
    {
        $this->calls[] = ['pairArray', [$k, $v]];
    }

    public function hook($k, Closure $callback): void
    {
        $this->calls[] = ['hook', [$k, $callback]];
    }

    public function fluent($a, $b = null): self
    {
        $this->calls[] = ['fluent', ['a' => $a, 'b' => $b]];

        return $this;
    }

    public function other($a): Recorder
    {
        $this->calls[] = ['other', [$a]];

        return new self;
    }

    public function void($a): void
    {
        $this->calls[] = ['void', [$a]];
    }
}
```

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\Interpreter;

/** A `__call` surface and a static receiver. */
final class Statics
{
    /** @var list<array{0: string, 1: array<array-key, mixed>}> */
    public static array $calls = [];

    public static function configure($x): void
    {
        self::$calls[] = ['configure', [$x]];
    }

    public function __call(string $name, array $arguments): void
    {
        self::$calls[] = [$name, $arguments];
    }
}
```

`tests/Fixtures/Interpreter/functions.php` holds the free function the ρ tests reference (moved from
`tests/Fixtures/Engine/functions.php`).

### 7.2 `tests/Interpreter/InterpreterTest.php`

Pure Pest, no Testbench. Add `uses()->in('Interpreter')` to `tests/Pest.php` without the Orchestra `TestCase`.
Each test is one conformance item of [manifest-interpreter.md §11](manifest-interpreter.md).

```php
<?php

declare(strict_types=1);

use ZeroToProd\LaravelDeclaration\Tests\Fixtures\Interpreter\Recorder;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\Interpreter\Statics;
use ZeroToProd\Manifest\Interpreter;

beforeEach(function (): void {
    $this->r = new Recorder;
    $this->i = new Interpreter;
    Statics::$calls = [];
});

it('calls a present key with no arguments for null and true, in manifest order', function (): void {
    $this->i->body($this->r, ['none' => null, 'zero' => true]);

    expect($this->r->calls)->toBe([['none', []], ['zero', []]]);
});

it('lets PHP reject true on a method that requires an argument', function (): void {
    $this->i->body($this->r, ['one' => true]);
})->throws(ArgumentCountError::class);

it('passes false as a scalar', function (): void {
    $this->i->body($this->r, ['one' => false]);

    expect($this->r->calls)->toBe([['one', [false]]]);
});

it('reads a list by the first parameter: argument when array-typed, spread when variadic, otherwise one call per item', function (): void {
    $this->i->body($this->r, ['typed' => [1, 2], 'spread' => [1, 2], 'one' => [1, 2]]);

    expect($this->r->calls)->toBe([['typed', [[1, 2]]], ['spread', [1, 2]], ['one', [1]], ['one', [2]]]);
});

it('reads a nested list item as the argument', function (): void {
    $this->i->body($this->r, ['one' => [[1, 2]]]);

    expect($this->r->calls)->toBe([['one', [[1, 2]]]]);
});

it('reads entries on a two-parameter method, fanning a list value out unless the second parameter is array-typed', function (): void {
    $this->i->body($this->r, ['pair' => ['a' => 1, 'b' => [2, 3]], 'pairArray' => ['b' => [2, 3]]]);

    expect($this->r->calls)->toBe([['pair', ['a', 1]], ['pair', ['b', 2]], ['pair', ['b', 3]], ['pairArray', ['b', [2, 3]]]]);
});

it('makes a λ from a map under a Closure parameter', function (): void {
    $this->i->body($this->r, ['hook' => ['K' => ['one' => 1]]]);

    [$name, [$key, $closure]] = $this->r->calls[0];
    $inner = new Recorder;
    $closure($inner);

    expect([$name, $key])->toBe(['hook', 'K'])
        ->and($inner->calls)->toBe([['one', [1]]]);
});

it('reads a row as named arguments and chains the other keys on the return', function (): void {
    $this->i->body($this->r, ['fluent' => ['a' => 1, 'one' => 2]]);

    expect($this->r->calls)->toBe([['fluent', ['a' => 1, 'b' => null]], ['one', [2]]]);
});

it('threads the chain through a different return object', function (): void {
    $this->i->body($this->r, ['fluent' => ['a' => 1, 'other' => 2, 'one' => 3]]);

    expect($this->r->calls)->toBe([['fluent', ['a' => 1, 'b' => null]], ['other', [2]]]); // one(3) ran on the new Recorder
});

it('reads a list of rows as one call per row even on an array-typed first parameter', function (): void {
    $this->i->body($this->r, ['typed' => [['x' => [1]], ['x' => [2]]]]);

    expect($this->r->calls)->toBe([['typed', [[1]]], ['typed', [[2]]]]);
});

it('lets PHP fail a chain on a void return', function (): void {
    $this->i->body($this->r, ['void' => ['a' => 1, 'one' => 2]]);
})->throws(Error::class, 'Call to a member function one() on null');

it('lets PHP fail an unknown key', function (): void {
    $this->i->body($this->r, ['nope' => 1]);
})->throws(Error::class, 'Call to undefined method');

it('spreads onto a __call surface', function (): void {
    $this->i->body(new Statics, ['anything' => [1, 2], 'again' => 'x']);

    expect(Statics::$calls)->toBe([['anything', [1, 2]], ['again', ['x']]]);
});

it('treats a key containing a namespace separator as a static receiver, on any receiver including null', function (): void {
    $this->i->body(null, [Statics::class => ['configure' => 'x']]);

    expect(Statics::$calls)->toBe([['configure', ['x']]]);
});

it('passes a map whose first key is not a parameter name as the argument on a one-parameter method', function (): void {
    $this->i->body($this->r, ['one' => ['nope' => 1]]);

    expect($this->r->calls)->toBe([['one', [['nope' => 1]]]]);
});

it('hands every non-λ argument to ρ with the parameter it fills', function (): void {
    $seen = [];
    $i = new Interpreter(function (mixed $value, ?ReflectionParameter $parameter) use (&$seen): mixed {
        $seen[] = $parameter?->getName();

        return is_string($value) ? strtoupper($value) : $value;
    });

    $i->body($this->r, ['pair' => ['a' => 'x'], 'hook' => ['K' => ['one' => 1]]]);

    expect($this->r->calls[0])->toBe(['pair', ['a', 'X']])
        ->and($seen)->toBe(['v']);                // an entry key is passed raw (rule 7); the λ never reached ρ
});
```

### 7.3 Host tests

- `tests/Feature/ResolveTest.php` (moved): construct with `Resolve::interpreter($this->app)` and assert through
  the application: `registered: {bind: {…}}` binds; `booted: [Class@method]` is called with container injection;
  `instance: {k: file.php}` binds the file's value; `useAppPath: src` is `base_path('src')`; a `.php` reference
  under a Closure parameter that returns a string throws `LogicException`.
- `tests/Feature/ManifestStoreTest.php` (moved): add `body()` strips exactly `ManifestStore::DATA`.
- `tests/Feature/SchemaRegistrationTest.php`: keeps every assertion; it exercises `GuardedBlueprint` end to end.
  Add two cases the old guard could not express: a table body whose index targets a column added earlier in the
  same body is applied (committed-state feeding), and `string: {column: x, change: ~}` on a missing column is skipped.
- Acceptance and Feature suites: only the manifest shapes of §6 change; expectations stay, except the
  precedence expectations in §6.3.
- Delete every test that asserted schema validity (`validates(...)`, `--check`, `x-manifest`).

---

## 8. Order of execution

1. Add `interpreter/Interpreter.php` and `tests/Interpreter/*`; run `vendor/bin/pest tests/Interpreter` green.
2. Add `src/Internal/Resolve.php`, `Tally.php`, `GuardedBlueprint.php`; rewrite `ManifestStore`,
   `ManifestServiceProvider`, `MigrateCommand`, `LaravelDeclarationProvider`.
3. Delete the files of §0; edit `composer.json`, `composer-require-checker.json`, `bin/*`, `phpstan.neon`,
   `CLAUDE.md`; `composer update --lock`.
4. Migrate the manifests of §6 and the test expectations of §7.3; rewrite the README sections of §5.7.
5. `composer check` — pint, rector, phpstan (level 9 over `interpreter`, `src`, `tests`), 100 % coverage,
   `interpreter-check`.

---

## 9. Behavioral breaks (all deliberate)

| # | before | after | why |
| --- | --- | --- | --- |
| 1 | an unknown key or wrong shape failed `laravel-declaration:validate` | fails at dispatch with PHP's `Error`/`TypeError`/`ArgumentCountError` | no validation layer |
| 2 | `make: {FQCN: {…}}`, `when: {FQCN: {…}}` | rows (§6.1) | `form: entries` removed; `make`'s `$parameters` is array-typed |
| 3 | `prepend*` lists reversed | manifest order = call order (§6.3) | `order: reverse` removed |
| 4 | `only: [a, b]` passed the list | fans out; use `{methods: [a, b]}` (§6.2) | `list: argument` removed |
| 5 | a chained key after a `void`/scalar return kept the receiver | PHP's `Error` | the chain is a PHP `->` expression |
| 6 | a root FQCN key worked only for a projected all-static class | any `\` key is a static receiver | lexical rule |
| 7 | the migrate guard judged each call against the manifest (`rest` look-ahead for `change`) | judges the built blueprint against the live schema | policy in the receiver; `change` is a column attribute by then |
| 8 | `declaration:generate-manifest-schema`, `laravel-declaration:validate`, `composer schema` | removed | — |
| 9 | editor completion via `yaml-language-server: $schema` | none | no schema; the native method's docblock is the reference |
