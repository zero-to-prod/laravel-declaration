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
- 0.1 Project native Laravel classes 1:1.
- 0.2 Project native Laravel contracts 1:1.
- 0.3 Project native Laravel registries 1:1.
- 0.4 Every **declaration class** (`App`, `Router`, `Routes`, `Kernel`, …) mirrors exactly one Laravel class (`Illuminate\Foundation\Application`, `Illuminate\Routing\Router`, …).
- 0.5 If a capability exists in Laravel, map it in Tier 1.
- 0.6 Never replace a Laravel capability with glue or synthetic attributes.

### Rule 1 — Key = method name
- 1.1 The **manifest** key and the **declaration property** name are **identical strings**.
- 1.2 The **manifest** key and the const name are **identical strings**.
- 1.3 The **manifest** key and the **native method** name are **identical strings**.

Identical strings:
- `middlewareGroup`
- `aliasMiddleware`
- `prependMiddlewareToGroup`
- `addRoute`
- `resource`

- 1.4 No invented verbs or nouns.
- 1.5 If Laravel doesn't have that method name, the key is wrong.

### Rule 2 — Argument forwarding
- 2.1 Pass references through untouched.
- 2.2 Pass scalar arguments through untouched.
- 2.3 Signature order comes from the **native method**.
- 2.4 Optionality comes from the **native method**.
- 2.5 Types come from the **native method**.
- 2.6 Never reshape arguments.
- 2.7 Never default arguments.
- 2.8 Never normalize arguments.

### Rule 3 — Fail where Laravel fails
- 3.1 No custom DSL.
- 3.2 No custom validation layers.
- 3.3 Propagate native exceptions (`BadMethodCallException` on dynamic dispatch, native argument errors).
- 3.4 No validation.
- 3.5 Pass everything through.

### Rule 4 — Stateless, immutable, cache-safe
- 4.1 **Declaration classes** are `final readonly`.
- 4.2 Providers only *register/configure* in `boot()`.
- 4.3 No request-time state.
- 4.4 No singletons mutated at runtime.
- 4.5 Fully compatible with `route:cache`.
- 4.6 Fully compatible with `config:cache`.
- 4.7 Do not cache classes — only native php types.

### Rule 5 — Zero cross-subsystem orchestration
- 5.1 Pure registration and configuration.
- 5.2 A provider wires one **component** to one Laravel manager.
- 5.3 No provider reads routes of another **component**.
- 5.4 No provider reads queries of another **component**.
- 5.5 No provider reads responses of another **component**.

### Rule 6 — Optionality
- 6.1 Everything in the **manifest** is opt-in.
- 6.2 Missing keys do not throw exceptions: behavior is simply not triggered.
- 6.3 Missing values must be handled by the **schema**.
- 6.4 It is assumed the **manifest** is validated by the **schema**.
- 6.5 No runtime validation.

## Highest Order Techniques

### Rule 7 — Declarative / AOP (Attribute Oriented Programming)
- 7.1 Behavior lives in attributes on properties.
- 7.2 Attributes are consumed by reflection.
- 7.3 Behavior never lives in `match`/`switch` chains.

```php
#[Key, Binding,     Describe([Describe::default => []])]  // per-key call: ->method($key, $value)
#[Key, Setter,      Describe([Describe::nullable => true])] // one call when non-null
#[Key, AppendTo,    Describe([Describe::default => []])]  // append, order preserved
#[Key, PrependTo,   Describe([Describe::default => []])]  // append reversed
#[Key, Append,      Describe([Describe::default => []])]  // callback form
public array $middlewareGroup;
```

- 7.4 Dispatch is uniform: `DeclarationClass::`**`selected(Binding::class)`** → `$Manager->{$method}(...)`.

### Rule 8 — Dynamic Dispatch
- 8.1 Keep naming vertically aligned.
- 8.2 One property name drives the key.
- 8.3 One property name drives the method.
- 8.4 One property name drives the call.
- 8.5 Add a Laravel method by adding one attributed property — no new dispatch code.
- 8.6 `Routes::registrars()` is the canonical map.

### Rule 9 — Exact vertical naming & casing
- 9.1 Property name = `const` name = **manifest** key = native Laravel method name (Laravel's own casing).
- 9.2 Classes `PascalCase`.
- 9.3 Properties/method-keys `camelCase` (Laravel's casing, never ours).
- 9.4 Local variables `PascalCase` (`$Manifest`, `$Router`).
- 9.5 Namespace `Attributes\Attributes`.
- 9.6 The docblock states the exact native call(s) per entry: `one Router::middlewareGroup($name, $middleware) per entry`.

### Rule 10 — Argument forwarding
- 10.1 Providers forward values verbatim:

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