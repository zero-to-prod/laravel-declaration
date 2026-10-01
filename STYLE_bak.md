# Style Guide

## Definitions
- `component`: A subsystem of the Laravel framework such as `Illuminate\Foundation\Application`
- `manifest`: A map of Laravel method names to arguments represented as a `.yml` file
- `declaration class`: A `final readonly` class mirroring exactly one native Laravel class (`Router` → `Illuminate\Routing\Router`), holding one attributed const+property pair per native method
- `declaration property`: A property on a declaration class whose name is identical to the `manifest` key, the const name, and the native method name
- `native method`: The real Laravel method a declaration forwards to; source of truth for key casing, signature order, optionality, and types
- `binding` (`Binding` attribute): Marks a per-entry call — `->method($key, $value)` invoked once per `manifest` key/value pair
- `setter` (`Setter` attribute): Marks a single call — `->method($value)` invoked once when the `manifest` value is non-null
- `append` (`Append`, `AppendTo`, `PrependTo` attributes): Marks array-valued keys that accumulate into a target (`AppendTo` appends in order, `PrependTo` appends reversed, `Append` uses a callback form)
- `Describe`: Attribute carrying per-key schema metadata (defaults, nullable) consumed by the `schema`, never by runtime validation
- `selected(Binding::class)`: The uniform dispatch mechanism — reflection returns the method names of declaration properties carrying the given attribute, e.g. `Routes::registrars()`
- `declaration provider`: A `ServiceProvider` whose `boot()` only registers/configures one `component` via `selected(...)` dispatch loops; no request-time state, no cross-subsystem reads
- `schema`: `manifest.schema.json` — the JSON Schema assumed to validate the `manifest`; the only validation layer

## Highest Value

**PURE STATELESS LARAVEL FRAMEWORK DECLARATIVE API MAP**
This package is a thin, stateless, declarative wrapper over Laravel's public API. 
- Keys map to function names
- Values map to function signatures
- Forward data to Laravel. Nothing more.

### Rule 0 — Declarative projection
Project native Laravel classes, contracts, and registries 1:1.
Every **declaration class** (`App`, `Router`, `Routes`, `Kernel`, …) mirrors exactly one Laravel class (`Illuminate\Foundation\Application`, `Illuminate\Routing\Router`, …). 
If a capability exists in Laravel, map it in Tier 1; never replace it with glue or synthetic attributes.

### Rule 1 — Key = method name
The **manifest** key, the **declaration property**, the const, and the **native method** name are **identical strings**: 
- `middlewareGroup`
- `aliasMiddleware` 
- `prependMiddlewareToGroup` 
- `addRoute`
- `resource` 

No invented verbs or nouns. 
If Laravel doesn't have that method name, the key is wrong.

### Rule 2 — Argument forwarding
Pass references and scalar arguments through untouched. 
Signature order, optionality, and types come from the **native method** — never reshaped, defaulted, or normalized.

### Rule 3 — Fail where Laravel fails
No custom DSL, no custom validation layers. 
Propagate native exceptions (`BadMethodCallException` on dynamic dispatch, native argument errors). 
No validation. 
Pass everything through.

### Rule 4 — Stateless, immutable, cache-safe
**Declaration classes** are `final readonly`. 
Providers only *register/configure* in `boot()`. 
No request-time state, no singletons mutated at runtime. 
Fully compatible with `route:cache` and `config:cache`.
Do not cache classes only native php types.

### Rule 5 — Zero cross-subsystem orchestration
Pure registration and configuration. 
A provider wires one **component** to one Laravel manager. 
No provider reads routes, queries, or responses of another **component**.

### Rule 6 — Optionality
Everything in the **manifest** is opt-in.
Missing keys do not throw exceptions: behavior is simply not triggered.
Missing values must be handled by the **schema**. 
It is assumed the **manifest** is validated by the **schema**.
No runtime validation.

## Highest Order Techniques

### Declarative / AOP (Attribute Oriented Programming)
Behavior lives in attributes on properties, consumed by reflection — never in `match`/`switch` chains.

```php
#[Key, Binding,     Describe([Describe::default => []])]  // per-key call: ->method($key, $value)
#[Key, Setter,      Describe([Describe::nullable => true])] // one call when non-null
#[Key, AppendTo,    Describe([Describe::default => []])]  // append, order preserved
#[Key, PrependTo,   Describe([Describe::default => []])]  // append reversed
#[Key, Append,      Describe([Describe::default => []])]  // callback form
public array $middlewareGroup;
```

Dispatch is uniform: `DeclarationClass::`**`selected(Binding::class)`** → `$Manager->{$method}(...)`.

### Dynamic Dispatch
Keep naming vertically aligned: one property name drives the key, the method, and the call. Add a Laravel method by adding one attributed property — no new dispatch code. `Routes::registrars()` is the canonical map.

### Exact vertical naming & casing
- Property name = `const` name = **manifest** key = native Laravel method name (Laravel's own casing).
- Classes `PascalCase`; properties/method-keys `camelCase` (Laravel's casing, never ours).
- Local variables `PascalCase` (`$Manifest`, `$Router`); namespace `Attributes\Attributes`.
- The docblock states the exact native call(s) per entry: `one Router::middlewareGroup($name, $middleware) per entry`.

### Argument forwarding
Providers forward values verbatim:

```php
foreach (RouterDeclaration::selected(Binding::class) as $method) {
    foreach ($Manifest->router->{$method} as $key => $value) {
        $Router->{$method}($key, $value);
    }
}
```

## Canonical Skeleton

1. `src/<Subsystem>.php` — `final readonly`, `use Internal\DataModel`, one const+property pair per **native method**, attributed.
2. `src/Providers/<Subsystem>DeclarationServiceProvider.php` — `boot(?Manifest $Manifest = null, ?<Native> $X = null)`, early return, then `selected(...)` dispatch loops only.
3. `src/Manifest.php` — nullable typed property + `const string <key> = '<key>'`.
4. Feature tests proving native exception behavior and cache-safety.

## Checklist before shipping a new mapping

- [ ] Every key matches a **native method** name character-for-character.
- [ ] Arguments forwarded untouched; defaults come from **`Describe`**, not opinions.
- [ ] Failure mode is Laravel's (native exception), not a bespoke message/DSL.
- [ ] No `match`/`switch` added where an attribute + `selected()` suffices.
- [ ] `readonly`, stateless, no cross-subsystem reads.
- [ ] `composer check` passes.