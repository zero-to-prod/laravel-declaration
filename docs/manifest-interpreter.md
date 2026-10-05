# Manifest Interpreter — Specification

A **manifest** is PHP written as data. The **interpreter** reads it the way a browser reads HTML: keys are
method names, values are arguments, nesting is composition, and the output is **execution against the PHP
classes the manifest names**. Nothing is translated, validated, or defaulted on the way; PHP's own call
semantics decide what happens, and PHP's own errors say what went wrong.

This document specifies the general-purpose interpreter to be extracted from `src/Internal/Engine/`
(**Laravel Declaration** is one host of it). It is a specification only; no code is implemented here.

| | |
| --- | --- |
| Location | `interpreter/` (PSR-4 `ZeroToProd\Manifest\` — already mapped in `composer.json`) |
| Dependencies | none: `php ^8.4` only; no `Illuminate\*`, no `ZeroToProd\LaravelDeclaration\*` |
| Public surface | one class, `ZeroToProd\Manifest\Interpreter`, three methods (§5) |
| Removed concepts | manifest schema, schema generator, schema validation, `x-manifest` curation, data keys, guard hook, block map |

---

## 1. Scope

**In scope**

- Reading a decoded manifest (a PHP array as YAML/JSON decoded it) and dispatching it onto PHP.
- Deriving every decision from the manifest value's **shape** and the target method's **reflected signature**.
- One extension point, **ρ** (§6), through which a host turns manifest values into PHP values it alone understands.

**Out of scope (non-goals)**

- Any schema, intermediate representation, or transformation of the manifest.
- Any validation, defaulting, normalization, coercion, or error handling.
- Any knowledge of a framework, a lifecycle, a container, a file system, or a class name.
- Any per-key configuration ("curation"). There is no table of keys anywhere.

---

## 2. Vocabulary

| Term | Meaning | PHP |
| --- | --- | --- |
| **receiver** | the thing a body is applied to: an object, or a class-string for static calls | `$t` / `T::class` |
| **body** | a map of **method name → value**, applied to one receiver in manifest order | `$t->a(); $t->b();` |
| **key** | a method name on the receiver, or a fully qualified class name (§4.6) | `a` |
| **value** | the arguments of that call, in one of the forms of §4.3 | `(...)` |
| **Σ** (signature) | the method's reflected parameters: name, type, variadic | `ReflectionMethod::getParameters()` |
| **row** | a map whose first key is a parameter name of the method: one call with **named arguments** | `$t->m(a: 1, b: 2)` |
| **chain** | the keys of a row that are not parameter names; they ride the **return value** | `$t->m(a: 1)->c()->d()` |
| **entries** | a map whose first key is not a parameter name, on a method with two or more parameters: one `m($k, $v)` per entry | `$t->m('k1', $v1); $t->m('k2', $v2);` |
| **λ** (lambda) | a map under a parameter that accepts a `Closure`: a body on the closure's first argument | `function (object $o) { $o->a(); }` |
| **ρ** (resolve) | the host's value hook, identity by default | — |
| **static receiver** | a key that is a fully qualified class name; its value is a body applied statically | `T::a(); T::b();` |

---

## 3. Input and output

**Input.** A receiver and a body. The body is the manifest exactly as decoded: maps (string-keyed PHP arrays,
ordered), lists (`array_is_list`), scalars, `null`. The interpreter never reads a file and never parses a format.

**Output.** The side effects of the PHP calls the body denotes, performed in manifest order. `body()` returns
nothing. Nothing is retained between calls: the interpreter holds ρ and nothing else.

**Signature source.** The only metadata the interpreter consults is PHP reflection on the receiver at dispatch
time (`new ReflectionMethod($receiver, $key)`). When the receiver has no such method (`method_exists` is
`false`, i.e. a `__call` / `__callStatic` surface), the signature is taken to be `(mixed ...$arguments)` — which is
exactly what PHP hands to `__call`.

---

## 4. Reading rules

### 4.1 A body

```
body(t, B):   for each (key, value) in B, in order:
                  key contains "\"  →  body(key, value)                       (§4.6 static receiver)
                  otherwise         →  for each (args, rest) in calls(Σ(t, key), value):
                                            r = t is string ? key::m(...args) : t->m(...args)
                                            chain(r, rest)
```

`m` is the key verbatim — the manifest key and the PHP method name are **identical strings**, in PHP's casing.

### 4.2 Parameter predicates (from `ReflectionParameter` only)

| predicate | true when |
| --- | --- |
| `variadic(p)` | `p->isVariadic()` |
| `array(p)` | the declared type is, or is a union containing, `array` or `iterable` |
| `closure(p)` | the declared type is, or is a union containing, `Closure` or `callable` |
| `names(Σ)` | the parameter names in declaration order |

An untyped or `mixed` parameter satisfies none of the type predicates. No docblock is ever read.

### 4.3 The forms — `calls(Σ, value) → list<(args, rest)>`, first match wins

`p0`, `p1` are the first and second parameters, `n = |Σ|`, `N = names(Σ)`. `arg(x, p)` is defined in §4.4.

| # | value | condition | calls |
| --- | --- | --- | --- |
| 1 | `null` or `true` | — | `m()` |
| 2 | scalar (`string`, `int`, `float`, `false`) | — | `m(arg(value, p0))` |
| 3 | list | every item is a row (a map whose first key ∈ N) | one call per item, by rule 8 |
| 4 | list | `variadic(p0)` | `m(...arg(item, p0) for each item)` |
| 5 | list | `array(p0)` | `m(arg(value, p0))` — the list **is** the argument |
| 6 | list | otherwise | one call per item; a map item is read by rules 7–9, any other item is `m(arg(item, p0))` (a nested list is the argument) |
| 7 | map, first key ∉ N | `n ≥ 2` and not `array(p0)` | **entries**: per `(k, e)`: `m(k, arg(e, p1))`; a list `e` fans out as `m(k, arg(i, p1))` per item unless `array(p1)` |
| 8 | map, first key ∈ N | — | **row**: `m(...named)` where `named[k] = arg(value[k], p_k)` for every key `k ∈ N`; every other key is `rest`, in order |
| 9 | map, first key ∉ N | otherwise | `m(arg(value, p0))` — the map **is** the argument |

Reading the table as PHP: rows 1–2 are `m()` and `m($x)`; 4 is `m(...$xs)`; 5 and 9 are `m([...])`; 3 and 6 are
a loop of calls; 7 is the loop `foreach ($map as $k => $v) m($k, $v)`; 8 is PHP 8 **named arguments** followed
by `->` chaining. There is no eleventh form, and nothing is decided by the method's name or class.

**One principle for lists, at every depth.** A list is one argument when the receiving parameter is `array`
(or spread when variadic); otherwise it fans out into one call per item. That is rule 5/4 vs 6 for the key's
value and the `unless array(p1)` clause of rule 7 for an entry's value.

**A present key always calls.** `~` and `true` call with no arguments; `false` is a scalar and calls with
`false`. Absence is the only opt-out.

### 4.4 An argument — `arg(x, p)`

```
arg(x, p) = x is a map ∧ closure(p)  →  λ(x)
            otherwise                 →  ρ(x, p)          p may be null (a __call surface)
```

**λ** is `function (object $receiver): void { body($receiver, x) }`. PHP ignores the extra arguments a callee
passes to it (`afterResolving` passes `($object, $app)`; the body runs on `$object`).

**ρ** is the host hook (§6); the default is identity, so every value that is not a λ reaches PHP untouched.

### 4.5 A chain — `chain(r, rest)`

```
chain(r, rest):  for each (key, value) in rest, in order:
                     for each (args, deeper) in calls(Σ(r, key), value):
                         r2 = r->key(...args)
                         chain(r2, deeper)
                         r = r2
```

The rest of a row is a **fluent expression**: each key is called on the return of the previous one, exactly as
`->` reads in PHP. `foreignId: {column: user_id, constrained: users, cascadeOnDelete: ~}` is
`$t->foreignId(column: 'user_id')->constrained('users')->cascadeOnDelete()`. If a return is not an object, the
next call is PHP's `Error: Call to a member function … on null`; the interpreter does not substitute the
previous receiver.

### 4.6 A static receiver

A key that contains the namespace separator `\` is a **fully qualified class name**, never a method name
(PHP method names cannot contain `\`). Its value is a body applied to that class-string: every call is static
(`Class::m(...)`). Reflection is taken on the class; a non-static method on a class-string receiver is PHP's
`Error: Non-static method … cannot be called statically`. A class in the global namespace is written with its
leading separator (`\DateTimeImmutable`).

This is how a manifest **references classes directly by their namespaces and declares values against them**:

```yaml
Illuminate\Pagination\Paginator:        # a static receiver
  useBootstrapFive: ~                   # Paginator::useBootstrapFive()
  defaultView: pagination::custom       # Paginator::defaultView('pagination::custom')
```

A static receiver may appear in any body, including a λ body and the root. Inside **entries** (rule 7) a
class-string is an argument, not a key: `afterResolving: {Illuminate\Routing\Router: {…}}` is
`afterResolving('Illuminate\Routing\Router', λ)` because `afterResolving` is the key and the class is `k`.

---

## 5. Public API

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\Manifest;

use Closure;
use ReflectionParameter;

final class Interpreter
{
    /**
     * @param  null|Closure(mixed $value, ?ReflectionParameter $parameter): mixed  $resolve  ρ — identity when null
     */
    public function __construct(?Closure $resolve = null);

    /**
     * `$t->a(); $t->b();` — apply a body to a receiver, in manifest order.
     *
     * An object receiver dispatches with `->`, a class-string with `::`, `null` is a body with no receiver (only
     * class-name keys are meaningful; any other key is PHP's Error on null).
     *
     * @param  array<array-key, mixed>  $body
     */
    public function body(object|string|null $receiver, array $body): void;

    /**
     * λ — a body as a PHP closure, `function (object $receiver): void`. Public so a host's ρ can produce one for
     * a parameter PHP does not declare as Closure (see §7.2).
     *
     * @param  array<array-key, mixed>  $body
     */
    public function closure(array $body): Closure;
}
```

That is the entire surface. Implementation guidance, not contract: one file, no state beyond ρ, the private
helpers `calls`, `chain`, `signature`, and the three predicates of §4.2. The functions it needs are PHP's:
`ReflectionMethod`, `method_exists`, `array_is_list`, `array_key_first`, `array_all`, `str_contains`, `is_scalar`.

---

## 6. The extension point — ρ

ρ exists because a manifest can only carry scalars, lists, and maps, while PHP methods sometimes want
objects a host knows how to make (a `Closure` from `Class@method`, a value from a `.php` file, an absolute
path from a relative one). The interpreter does not know any of these and must not learn them.

Contract:

- Called once per argument, after the form is chosen and after λ has been considered (§4.4).
- Receives the raw manifest value and the `ReflectionParameter` it will fill, or `null` for a `__call` surface.
- Returns the PHP value to pass. Identity is a correct ρ.
- May call `Interpreter::closure()` to turn a map into a λ for a parameter whose declared type is not `Closure`.
- Is the only place a host opinion may live; the interpreter never branches on what ρ returns.

---

## 7. Hosting it (what `src/` becomes)

The Laravel package is one host. Its responsibilities, none of which leak into the interpreter:

### 7.1 Root receiver and timing

`$interpreter->body($this->app, $manifest)` in `register()`. Root keys are `Application` methods. Timing is the
application's own lifecycle methods written in the manifest (`registered`, `booting`, `booted`, `afterResolving`,
`make`); the interpreter runs each λ when PHP invokes it.

### 7.2 The Laravel ρ

The former `Resolve` class, minus the schema. It receives the `ReflectionParameter`, so it knows the declaring
class, method and parameter name and can apply its vocabularies without any curation table:

| manifest value | host decision |
| --- | --- |
| `Class@method`, `Class::method`, invokable `Class`, `function` under a `Closure` parameter | a closure that pairs positional arguments by name and lets the container inject the rest |
| `*.php` | `require` once; its return value |
| a relative path under a path parameter the host recognizes | `base_path()`-relative |
| a **map** under an **untyped** callback parameter the host knows (`Application::registered/booting/booted/terminating($callback)`, `Router::group($routes)`, `ManagesEvents::composer($callback)`) | `$interpreter->closure($value)` — this replaces the `closure: [param]` curation |
| anything else | untouched |

### 7.3 Data the host reads itself

`requests`, `models`, `queries`, `schema`, `extra` were "data keys" the schema flagged. The host removes them
from the array before calling `body()` and keeps them in its own store; the interpreter never sees them.

### 7.4 Policy lives in receivers, not in the interpreter

The migrate command's guard (skip `create` when the table exists, skip a column that is present, …) is not an
interpreter feature. The host hands the interpreter the real `Builder` and substitutes the one object Laravel
lets it substitute without changing a signature: the `Blueprint`, through `Builder::blueprintResolver()`. A
`Blueprint` subclass overrides `build()` to decide a table verb whole and to prune an alter body against the live
schema just before its SQL runs. Reflection still sees the real signatures, the manifest is unchanged, and the
decision is in PHP where PHP already decides everything else. The complete code is in
[interpreter-migration-plan.md §4](interpreter-migration-plan.md).

### 7.5 Manifest shapes that change

Every former curation keyword is gone; the manifest expresses the same call with the forms of §4.3:

| was (schema-curated) | now (pure PHP reading) |
| --- | --- |
| `make: {Illuminate\Config\Repository: {set: {…}}}` (`form: entries`) | `make: {abstract: Illuminate\Config\Repository, set: {…}}` — a row whose rest chains on the made object; several `make`s are a list of rows |
| `when: {App\Foo: {needs: X, give: Y}}` (`form: entries`) | `when: {concrete: App\Foo, needs: X, give: Y}` → `when(concrete: …)->needs(X)->give(Y)` |
| `prependMiddlewareToGroup` with `order: reverse` | write the list in call order; the interpreter calls in manifest order |
| `list: argument` on an untyped first parameter | a row: `index: {columns: [a, b]}`; or let rule 6 fan out |
| `closure: [callback]` on an untyped parameter | host ρ (§7.2) |
| `data: true` root keys | host splits before dispatch (§7.3) |
| a root FQCN allowed only when "projected with a static key" | any `\` key, anywhere (§4.6) |
| a void or scalar return "keeps the receiver" in a chain | PHP's `Error` (§4.5) |
| an unknown key fails schema validation | PHP's `Error: Call to undefined method` at dispatch |

---

## 8. Worked examples (manifest → the PHP it executes)

**Container bindings** — rules 7 and 1

```yaml
bind:
  App\Contracts\Pdf: App\Services\DomPdf
singleton:
  App\Services\TenantContext: ~
tag:
  App\Reports\Cpu: reports
```

```php
$app->bind('App\Contracts\Pdf', 'App\Services\DomPdf');   // bind($abstract, $concrete = null, …): entries
$app->singleton('App\Services\TenantContext', null);        // ~ under an entry is null, as PHP would receive it
$app->tag('App\Reports\Cpu', 'reports');
```

**A resolved service and a fluent route** — rules 7 (λ via `closure(p1)`), 8, 4.5

```yaml
afterResolving:
  Illuminate\Routing\Router:
    pattern: {id: '[0-9]+'}
    middlewareGroup: {tenant: [App\Http\Middleware\TenantIdentified]}
    addRoute:
      - methods: GET
        uri: "posts/{id}"
        action: App\Http\Controllers\PostController
        name: posts.show
        where: {id: '[0-9]+'}
        middleware: [tenant]
```

```php
$app->afterResolving('Illuminate\Routing\Router', function (object $router): void {
    $router->pattern('id', '[0-9]+');                                            // pattern($key, $pattern): entries
    $router->middlewareGroup('tenant', ['App\Http\Middleware\TenantIdentified']); // array(p1): the list is the argument
    $router->addRoute(methods: 'GET', uri: 'posts/{id}', action: 'App\Http\Controllers\PostController')
        ->name('posts.show')                                                      // rest, fluent
        ->where('id', '[0-9]+')                                                   // where($name, $expression = null): entries on the Route
        ->middleware(['tenant']);                                                 // middleware($middleware = null): untyped → rule 6 fans out → middleware('tenant')
});
```

Note the last line: `middleware($middleware = null)` is untyped, so `[tenant]` fans out to one call per item —
the PHP reading, not a guess about the method. To pass the list whole, write the row: `middleware: {middleware: [tenant]}`.

**Configuration through a made object** — rules 8, 7, 9

```yaml
make:
  abstract: Illuminate\Config\Repository
  set:
    app.name: Tenant Console
    sentinel: {meters: true}
```

```php
$repository = $app->make(abstract: 'Illuminate\Config\Repository');
$repository->set('app.name', 'Tenant Console');
$repository->set('sentinel', ['meters' => true]);   // set($key, $value = null): $value is untyped, the map is the argument
```

**A schema body** — rules 7 (λ), 1, 6, 3, 8, 4.5

```yaml
make:
  abstract: db.schema
  create:
    users:
      id: ~
      string: [name, {column: email, unique: ~}]
      foreignId: {column: team_id, constrained: teams, cascadeOnDelete: ~}
      index: [[team_id, email]]
```

```php
$app->make(abstract: 'db.schema')->create('users', function (object $table): void {
    $table->id();
    $table->string('name');                                                       // rule 6: scalar item
    $table->string(column: 'email')->unique();                                    // rule 6: map item → row; unique() rides the ColumnDefinition (__call)
    $table->foreignId(column: 'team_id')->constrained('teams')->cascadeOnDelete(); // chain threads through two different return types
    $table->index(['team_id', 'email']);                                          // rule 6: a list item is the argument
});
```

**A static receiver at the root** — §4.6

```yaml
Illuminate\Pagination\Paginator:
  useBootstrapFive: ~
```

```php
Illuminate\Pagination\Paginator::useBootstrapFive();
```

---

## 9. Errors

The interpreter raises nothing of its own and catches nothing. Every failure is the one PHP or the callee
produces at the moment of the call:

| situation | what PHP produces |
| --- | --- |
| key is not a method and the receiver has no `__call` | `Error: Call to undefined method` |
| too few arguments | `ArgumentCountError` |
| wrong argument type (a λ where a string is expected, a string where an `array` is expected, …) | `TypeError` |
| a row names a parameter the method does not have | `Error: Unknown named parameter` |
| a non-static method on a class-string receiver | `Error: Non-static method … cannot be called statically` |
| a chain on a `void`, scalar or `null` return | `Error: Call to a member function … on null` |
| a `__call` receiver that rejects the name | its own exception (`BadMethodCallException` in Laravel's `Macroable`) |
| a class-name key that does not exist | `Error: Class "…" not found` (from reflection or the static call) |

A manifest that reads cleanly executes cleanly; a manifest that does not fails exactly where the equivalent
hand-written PHP would.

---

## 10. Constraints on the implementation

1. `interpreter/` contains PHP only; `composer-require-checker` runs over it with no allowed symbols outside `php`.
2. No `match`/`switch` over method names, class names or receivers. The only branches are the forms (§4.3), the
   argument rule (§4.4), the `\` key test (§4.6) and the object/string receiver test (§4.1).
3. No cache, no registry, no static state; a second `body()` call behaves like the first.
4. No reading of docblocks, attributes, or any file.
5. ρ is the only injectable; the constructor takes nothing else.

---

## 11. Conformance (given / when / then)

1. **Given** `{'a' => null, 'b' => true}` on an object with `a()` and `b()`, **when** read, **then** `a()` then `b()` are called once each, in that order, with no arguments.
2. **Given** `{'m' => false}`, **then** `m(false)` is called (a present key always calls).
3. **Given** `m(array $x)` and `{'m' => [1, 2]}`, **then** `m([1, 2])`; **given** `m($x)` untyped, **then** `m(1)` and `m(2)`; **given** `m(...$x)`, **then** `m(1, 2)`.
4. **Given** `m($k, $v)` and `{'m' => ['a' => 1, 'b' => [2, 3]]}`, **then** `m('a', 1)`, `m('b', 2)`, `m('b', 3)`; with `m($k, array $v)`, **then** `m('b', [2, 3])`.
5. **Given** `m($k, Closure $cb)` and `{'m' => ['K' => ['n' => 1]]}`, **then** `m('K', λ)`, and invoking λ with an object calls `n(1)` on it.
6. **Given** `m($a, $b)` returning `$this`, and `{'m' => ['a' => 1, 'c' => 2]}`, **then** `m(a: 1)` then `c(2)` on the return.
7. **Given** a return chain `m()->x()` where `x()` returns a different object with `y()`, and `{'m' => ['a' => 1, 'x' => null, 'y' => null]}`, **then** `y()` is called on `x()`'s return.
8. **Given** `m()` returning `void` and `{'m' => ['a' => 1, 'c' => null]}`, **then** PHP's `Error` propagates unchanged.
9. **Given** a receiver with `__call` and `{'anything' => [1, 2]}`, **then** `__call('anything', [1, 2])` (variadic signature, rule 4).
10. **Given** `{'Vendor\Thing' => ['configure' => 'x']}`, **then** `Vendor\Thing::configure('x')` is called statically, on any receiver including `null`.
11. **Given** ρ that upper-cases strings, **then** every scalar argument reaches the method upper-cased and every `ReflectionParameter` passed to ρ is the parameter the value fills; a λ is never passed to ρ.
12. **Given** `{'m' => ['nope' => 1]}` on `m($a)`, **then** PHP receives `m(['nope' => 1])`: `nope ∉ N`, so the map is the argument (rule 9). `Error: Unknown named parameter` can only arise inside a row, from a key PHP rejects.
