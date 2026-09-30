# Declarative Gate & Form Request Seam — Implementation Plan

Implements the last open row of [declarative-tier1-gap-inventory.md](declarative-tier1-gap-inventory.md):

> **7 | Form Request Seam (Tier 2) | `DeclaredRequest` | `[/]` | `authorize` resolves string refs via `Container::call` | `[/]` (narrower)**

Source of truth: `vendor/laravel/framework/src/Illuminate` (`laravel/framework` v13.33.0) — `Contracts/Auth/Access/Gate.php`, `Auth/Access/Gate.php`, `Auth/Access/Response.php`, `Auth/Middleware/Authorize.php`, `Auth/AuthServiceProvider.php`, `Foundation/Http/FormRequest.php`, `Foundation/Auth/Access/Authorizable.php`, `Container/Container.php`, `Container/BoundMethod.php`, `Support/ServiceProvider.php` — verified by direct source inspection on the date of this document, against package source (`src/`).

Goal: close the seam's narrower remainder — **policy-based authorization hooks** ([declarative-framework-api-mapping.md](declarative-framework-api-mapping.md) Domain 5: "`DeclaredRequest` (lacks policy-based authorization hooks)"; inventory §3.6: "the native `Gate::policy()` binding remains unmapped under the missing `gate:` Tier 1 row"; §1.7: the Authorization Gate Void) — by mapping the seam's `authorize` hook onto the native `Illuminate\Contracts\Auth\Access\Gate` API with dynamic dispatch, backed by the `Gate::policy()` / `Gate::define()` registry slice as a `gate:` Tier 1 block.

---

## 1. Verification — the claim and the remainder

### 1.1 Claim verified: `authorize` resolves string refs via `Container::call`

| Claim | Package source | Vendor source | Verdict |
|---|---|---|---|
| `authorize` string refs resolve through `Container::call` | `src/DeclaredRequest.php:173` — `resolve()` calls `$this->container->call($value, ['request' => $this, ...$parameters])` for every string | `Container/Container.php:788` → `BoundMethod::call()` (`Container/BoundMethod.php:25`): a bare class-string with `__invoke` gets `$defaultMethod = '__invoke'` (line 28); `Class@method` via `isCallableWithAtSign()` (line 216); `Class::method` static via `getCallReflector()` splitting `::` (line 141); namespaced functions via `ReflectionFunction`; named parameters matched by name first (`addDependencyForCallParameter`, line 165) | **True** |
| A bool `authorize` passes through | `src/DeclaredRequest.php:54` — `resolve()` returns non-strings untouched | `FormRequest::passesAuthorization()` (`Foundation/Http/FormRequest.php:344`) consumes `bool\|Response` | **True** |
| A returned `Response` is `->authorize()`d natively (throws when denied) | `src/DeclaredRequest.php:54` returns `Response` untouched | `FormRequest.php:349` — `$result instanceof Response ? $result->authorize() : $result`; `Auth/Access/Response.php:148-155` throws `AuthorizationException($message, $code)` | **True** |
| `false` → `failedAuthorization()`; absent → `true` | `src/DeclaredRequest.php:151` (`failedAuthorization(): never` runs the declared ref, then throws) | `ValidatesWhenResolvedTrait::validateResolved():17-22` — `! passesAuthorization()` → `failedAuthorization()`; `authorize === null` → `true` | **True** |

The existing suite already proves all four paths (`tests/Feature/DeclaredRequestTest.php`: `answers with the failedAuthorization reference`, `throws the default authorization exception`, `authorizes through an access response`, and every request without `authorize`).

### 1.2 The narrower remainder (what this plan closes)

- `src/Request.php:22` — `$authorize` is `bool|string|null`: the hook can say **a bool** or **"call this PHP"**. A policy check is expressible only by hand-writing PHP (`$request->user()->can('update', $post)` inside a reference class).
- No `gate` block exists anywhere: `src/Manifest.php` (no `gate` property), `manifest.schema.json` (no `gate` definition), `src/DefaultProviders.php` (no `GateDeclarationServiceProvider`).
- Vendor fact that makes this a *seam* gap and not a lint: `Container::call('App\Policies\PostPolicy@update', ['request' => $this])` cannot serve policy methods — `update(User $user, Post $post)` has no `$request` parameter, and `BoundMethod` would container-`make()` the unmatched `User` — a **concrete** class auto-resolves to a fresh, unauthenticated instance (the wrong value, verified against `Container/BoundMethod.php:165`); an interface or abstract throws `BindingResolutionException: Target [I] is not instantiable`. Policy checks need the **Gate**, whose native inspection API is the missing declarative surface.

### 1.3 Native `Gate` surface (v13.33.0, verified signatures)

`Illuminate\Contracts\Auth\Access\Gate` (`Contracts/Auth/Access/Gate.php:13-149`) — the contract carries the complete registry and inspection surface, so the seam dispatches on the contract (phpstan-clean, no concrete dependency):

| Method | Signature (contract) | Concrete notes (`Auth/Access/Gate.php`) |
|---|---|---|
| `policy` | `policy($class, $policy)` | line 286 — `$this->policies[$class] = $policy` |
| `define` | `define($ability, $callback)` | line 197 — accepts a `Closure`, a `Class@method` string, or an array callback (normalized to `Class@method`); a bare invokable class-string resolves and is called as `$policy(...)` |
| `check` | `check($abilities, $arguments = []): bool` | line 350 — `(new Collection($abilities))->every(...)` — all must pass; **one shared `$arguments`** |
| `any` / `none` | `any($abilities, $arguments = [])` / `none(...)` | lines 364/376 — any-granted / all-denied |
| `authorize` | `authorize($ability, $arguments = []): Response` | line 390 — `inspect(...)->authorize()` — **throws** when denied |
| `inspect` | `inspect($ability, $arguments = []): Response` | line 402 — never throws; returns `Response::allow()` / `Response::deny()` |
| `raw` | `raw($ability, $arguments = [])` | line 428 — `Arr::wrap($arguments)`, runs before/after callbacks, dispatches `GateEvaluated` |
| `allows` / `denies` | `allows($ability, $arguments = [])` / `denies(...)` | lines 326/338 — bool aliases of `check` |
| `forUser` | `forUser($user): static` | line 872 — copies `$abilities`, `$policies`, `$beforeCallbacks`, `$afterCallbacks`, `guessPolicyNamesUsingCallback` and `defaultDenialResponse` into the new instance — declared policies **apply** through it |
| `before` / `after` / `resource` | out of scope (§2.4) | lines 299/312/226 |

Policy resolution for an argument (`getPolicyFor`, `Gate.php:653-698`), in order: the explicit `policies[$class]` map → the `#[UsePolicy]` class attribute on the model (`getPolicyFromAttribute`, line 699) → guessed conventional names (`guessPolicyName`, line 724: `<dir>\Policies\{Model}Policy` candidates, first `class_exists` wins) → `policies` entries where the model `is_subclass_of` the key → the attribute on parents. `resolvePolicy($class)` = `$this->container->make($class)` (line 767). The explicit binding is the only one of these paths that is *declarable* — it is the missing native method the inventory names.

Policy-vs-ability resolution for a `check` (`resolveAuthCallback`, `Gate.php:621-645`), in order: a policy resolved from **`$arguments[0]`** (a non-string or unknown class-string makes `getPolicyFor()` return null) → the `define`d string callback (`stringCallbacks[$ability]`) → the `define`d closure — otherwise a null-resulting closure. Consequence, verified against source: a **route parameter name** argument (`post` → its bound value `'1'`) can only reach a policy method when the route binds a model; without a binding it must pair with a `gate.define`d string callback, which bypasses `getPolicyFor()`.

Native precedents the design reuses verbatim:

1. **`Illuminate\Auth\Middleware\Authorize`** (`Auth/Middleware/Authorize.php:55-115`) — Laravel's own declarative gate argument contract (`can:update,post`): `handle()` calls `$this->gate->authorize($ability, $this->getGateArguments($request, $models))`; `getGateArguments()` maps each model; `getModel()` (line 87): a class name (`str_contains($value, '\\')`, `isClassName()` line 103) passes through as the class-string, a route parameter name resolves to `$request->route($model, null)`, a quoted literal unquotes.
2. **`Authorizable::can()`** (`Foundation/Auth/Access/Authorizable.php:16-19`) — `$user->can($abilities, $arguments)` = `app(Gate::class)->forUser($this)->check($abilities, $arguments)` — the request-user seam idiom.
3. **`Gate::define` string callbacks** (`buildAbilityCallback`, `Gate.php:250-283`) — `Class@method` resolves the class and calls the method named by the ability (or `@method`); a bare class-string is called as an invokable.
4. **`ServiceProvider::callAfterResolving`** (`Support/ServiceProvider.php:310-317`) — registers `afterResolving` and runs immediately only `if ($this->app->resolved($name))` — the shipped `validator:` lifecycle (`Providers/ValidatorDeclarationServiceProvider.php:21`).
5. **The `Rule::when()` conditional-rule precedent** ([declarative-validator.md](declarative-validator.md) §2.6, implemented in `DeclaredRequest::conditionalRule()`) — an entry keyed by a native factory method name whose value is a map of that method's **native parameter names** (`condition`, `rules`, `defaultRules`).

Lifecycle (verified): `AuthServiceProvider::registerAccessGate()` (`Auth/AuthServiceProvider.php:57-63`) binds `GateContract::class` as a **singleton** in `register()` (line 24) with user resolver `fn () => call_user_func($app['auth']->userResolver())` — bound at declaration-provider boot, resolved lazily, so `callAfterResolving(GateContract::class, ...)` queues and runs on first `make()`, before any `check()` runs on that instance.

---

## 2. Design

### 2.1 Two artifacts, one row

1. **The `gate:` Tier 1 block (prerequisite slice)** — `src/Gate.php` (DataModel) + `Providers/GateDeclarationServiceProvider.php`. Every block key is a native `Gate` registry-method name (Rule 1); each map is one call per entry with the key as the first argument (Rule 2); reference strings pass through untouched (Rule 3 — `Gate::policy` takes class-strings, `Gate::define` takes `Class@method` natively). Applied once when the shared Gate first resolves.
2. **The seam's `authorize` map form (Tier 2)** — `Request::$authorize` widens to `bool|string|array`; `DeclaredRequest::authorize()` gains one branch that dispatches dynamically onto the native contract methods for the request user: `$this->container->call([$Gate, $method], $parameters)` — `BoundMethod` matches `ability`/`abilities`/`arguments` by **native parameter name**, so the implementation holds zero signature knowledge and no per-method code (dynamic dispatch; unknown method names fail with PHP's own `Error: Call to undefined method`, mismatched parameter names with `BindingResolutionException` — Laravel's own failures, Rule 7).

### 2.2 Value contract for `authorize`

| YAML shape | Dispatch | Returns |
|---|---|---|
| `authorize: true` / `authorize: false` | value → `passesAuthorization()` (unchanged, shipped) | `bool` |
| `authorize: <reference>` | `Container::call` (unchanged, shipped — §1.1) | `bool\|Response` |
| `authorize: {<Gate method>: {ability: …, arguments: …}}` | `$this->container->call([$Gate, $method], $parameters)` with `$Gate = GateContract::forUser($this->user())` | `bool` (`check`, `any`, `none`, `allows`, `denies`) or `Response` (`inspect`, `authorize`, `raw`) |

The map declares **one** Gate call: more than one key throws `LogicException` — the `conditionalRule()` precedent (declaring both `when` and `unless`).

### 2.3 Sub-map = the native parameter names

| YAML key | Native parameter | Resolution |
|---|---|---|
| `ability` | `$ability` of `allows`/`denies`/`authorize`/`inspect`/`raw` | passes through (a value) |
| `abilities` | `$abilities` of `check`/`any`/`none` — a string or a list | passes through per entry |
| `arguments` | `$arguments` (native default `[]`) | the native `Authorize::getGateArguments()` contract (§2.4) |
| *(any other key)* | — | two native outcomes, both verified against `BoundMethod::addDependencyForCallParameter()` (line 165): if the key displaces a **required** parameter (`ability` declared but the method names it `abilities`) → `BindingResolutionException: Unable to resolve dependency [Parameter #0 [ <required> $abilities ]] in class …` on first use — Laravel's own failure (Rule 7); if it displaces nothing (all required parameters already matched), the leftover key is appended as an extra positional argument and silently ignored — PHP's own call semantics |

### 2.4 `arguments` = the native `Authorize::getGateArguments()` contract

`arguments: post` in the manifest means exactly what `can:update,post` means on a route (§1.3 precedent 1): a class-string (contains `\`) passes through as the Gate argument; a route parameter name resolves to its bound value via the FormRequest's own accessor `$this->route($argument, null)` (the `Authorize::getModel()` call, with route-bound models already substituted by `SubstituteBindings` middleware); a quoted literal unquotes. A list resolves per entry (Rule 2); a non-string (e.g. an integer literal) passes through.

Native pairing rule (§1.3 `resolveAuthCallback`): the Gate resolves a **policy** only from `$arguments[0]`, so `arguments: <model class-string>` (or a route that binds the model) drives `gate.policy` methods, while a route-parameter-name argument without a model binding pairs with a `gate.define`d string callback — the `update-post`/`publish-post` fixture scenarios (§5.2).

### 2.5 Denied semantics (native, unchanged)

| Declared method | Denied result | Declared `failedAuthorization` hook |
|---|---|---|
| `check` / `any` / `none` / `allows` / `denies` | `false` → `failedAuthorization()` → declared ref runs, then `AuthorizationException` | runs |
| `inspect` / `raw` | `Response` returned → `passesAuthorization()` calls `$result->authorize()` → `AuthorizationException($message)` thrown inside the check | **does not run** — native `FormRequest.php:349` behavior for any `Response` |
| `authorize` | `Gate::authorize()` throws `AuthorizationException` directly | does not run |

`inspect` (Response with a message) and `check` (bool feeding the declared failure hook) are the recommended forms; all contract methods stay reachable through the single dynamic dispatch.

### 2.6 Scope boundary

This plan maps `gate.policy` and `gate.define` — the exact pair named by the audit (§1.7: "Map `gate:` directly to `Gate::define()` and `Gate::policy()` in Tier 1") and the inventory (§3.6: "the native `Gate::policy()` binding"). The remaining `gate:` Tier 1 members — `before`, `after`, `resource`, `allowIf`/`denyIf`, `guessPolicyNamesUsing` — stay with the Domain 9 `gate:` row, which narrows `[ ]` → `[/]` on implementation. `useBootstrap`-style runtime plumbing and the `Authorized` route middleware are untouched.

---

## 3. Implementation — complete code

### 3.1 `src/Gate.php` (new)

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class Gate
{
    use DataModel;

    public const string policy = 'policy';

    /** ≙ Gate::policy($class, $policy), one call per entry: the key is $class, the value $policy.
     *
     * @var array<string, string>
     */
    #[Describe([Describe::default => []])]
    public array $policy;

    public const string define = 'define';

    /** ≙ Gate::define($ability, $callback), one call per entry: the key is $ability, the value the
     *  `Class@method` reference (or a bare invokable class-string) — passed through untouched; Laravel
     *  resolves string callbacks natively (Gate::buildAbilityCallback()).
     *
     * @var array<string, string>
     */
    #[Describe([Describe::default => []])]
    public array $define;
}
```

### 3.2 `src/Providers/GateDeclarationServiceProvider.php` (new)

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Support\ServiceProvider;
use ZeroToProd\LaravelDeclaration\Gate;
use ZeroToProd\LaravelDeclaration\Manifest;

/** @internal */
class GateDeclarationServiceProvider extends ServiceProvider
{
    public function boot(?Manifest $Manifest = null): void
    {
        if (! $Manifest?->gate instanceof Gate) {
            return;
        }

        $this->callAfterResolving(GateContract::class, function (GateContract $AccessGate) use ($Manifest): void {
            foreach ($Manifest->gate->policy as $class => $policy) {
                $AccessGate->policy($class, $policy);   // ≙ Gate::policy($class, $policy)
            }

            foreach ($Manifest->gate->define as $ability => $callback) {
                $AccessGate->define($ability, $callback);   // ≙ Gate::define($ability, $callback)
            }
        });
    }
}
```

### 3.3 `src/Manifest.php` — add the block after `validator`

```php
    public const string gate = 'gate';

    #[Describe([Describe::nullable => true])]
    public ?Gate $gate;
```

### 3.4 `src/DefaultProviders.php` — register the provider

```php
use ZeroToProd\LaravelDeclaration\Providers\GateDeclarationServiceProvider;

// in $this->providers, immediately after ValidatorDeclarationServiceProvider::class:
            ValidatorDeclarationServiceProvider::class,
            GateDeclarationServiceProvider::class,
```

### 3.5 `src/Request.php` — widen `authorize`

```php
    public const string authorize = 'authorize';

    /** A bool passes through; a string is a reference (§2.2); a map declares one native Gate call:
     *  the key is the Illuminate\Contracts\Auth\Access\Gate method name and the value is a map of that
     *  method's native parameter names (declarative-requests.md §2.7).
     *
     * @var bool|string|array<string, array<string, mixed>>|null
     */
    #[Describe([Describe::nullable => true])]
    public bool|string|array|null $authorize;
```

### 3.6 `src/DeclaredRequest.php` — the seam dispatch

Add the import and constant, replace `authorize()`, and append two private methods:

```php
use Illuminate\Contracts\Auth\Access\Gate as GateContract;

    private const string arguments = 'arguments';

    public function authorize(): bool|Response
    {
        $authorize = $this->declaration()->authorize;

        /** @var bool|string $authorize — bool: value; string: a reference (§2.2); array: one native Gate call */
        return match (true) {
            $authorize === null => true,
            is_array($authorize) => $this->gateCall($authorize),
            default => $this->resolve($authorize),
        };
    }

    /** The map form: the key is a native Gate contract method name (Rule 1) — check, any, none, allows,
     *  denies, inspect, authorize, raw — and the value is a map of that method's native parameter names
     *  (the Rule::when() precedent, declarative-validator.md §2.6). One Gate call per `authorize` (Rule 2).
     *
     * @param  array<string, array<string, mixed>>  $authorize
     * @return bool|Response
     */
    private function gateCall(array $authorize): bool|Response
    {
        if (count($authorize) !== 1) {
            throw new LogicException('The `authorize` map declares '.count($authorize).' Gate methods; declare one.');
        }

        /** @var string $method — unknown names fail with `Error: Call to undefined method` (Rule 7) */
        $method = (string) array_key_first($authorize);

        $parameters = $authorize[$method];

        if (array_key_exists(self::arguments, $parameters)) {
            $arguments = $parameters[self::arguments];

            $parameters[self::arguments] = is_array($arguments)
                ? array_map($this->gateArgument(...), $arguments)   // one resolution per entry (Rule 2)
                : $this->gateArgument($arguments);
        }

        /** @var GateContract $Gate — the shared singleton, so `gate:`-declared policies and abilities apply */
        $Gate = $this->container->make(GateContract::class)->forUser($this->user());

        /** @var bool|Response — mismatched parameter names fail with `BindingResolutionException` (Rule 7) */
        return $this->container->call([$Gate, $method], $parameters);
    }

    /** ≙ Authorize::getModel(): a class-string is the argument itself; a route parameter name is its
     *  bound value; a quoted literal is the literal; anything else passes through.
     */
    private function gateArgument(mixed $argument): mixed
    {
        if (! is_string($argument) || str_contains($argument, '\\')) {
            return $argument;
        }

        return $this->route($argument, null)
            ?? (preg_match("/^['\"](.*)['\"]$/", $argument, $matches) ? $matches[1] : null);
    }
```

Notes: the `count($authorize) !== 1` guard also catches a YAML *list* (`authorize: [a, b]` hydrates to a 2-entry array). The arguments resolution lives inline in `gateCall` — the only consumer — leaving `gateArgument` as the sole port of `Authorize::getModel()` (§2.4). `Request::route($param, $default)` itself returns null cleanly when no route is bound (the `is_null($route)` short-circuit, `Illuminate/Http/Request.php:687`); in the shipped pipeline the dispatch is unreachable route-less anyway — `declaration()` fails first, at the same place every other seam member fails (declarative-requests.md §2.5). If phpstan level 9 objects to the dynamic callable `[$Gate, $method]`, annotate the line `/** @phpstan-ignore argument.type (the method name is manifest-declared; unknown names fail with Laravel's own exceptions) */`.

### 3.7 `manifest.schema.json`

Add `"gate": { "$ref": "#/definitions/gate" }` to root `properties`, and:

```json
"gate": {
  "description": "Illuminate\\Contracts\\Auth\\Access\\Gate registry methods: every key is a method name, its value the registry content — one call per entry. Applied when the Gate first resolves (callAfterResolving).",
  "type": ["object", "null"],
  "additionalProperties": false,
  "properties": {
    "policy": {
      "description": "≈ Gate::policy($class, $policy), one call per entry: the key is the model class-string, the value the policy class-string.",
      "type": "object",
      "additionalProperties": { "$ref": "#/definitions/reference" }
    },
    "define": {
      "description": "≈ Gate::define($ability, $callback), one call per entry: the key is the ability name, the value the 'Class@method' or invokable class-string reference, passed through untouched.",
      "type": "object",
      "additionalProperties": { "$ref": "#/definitions/reference" }
    }
  }
},
```

Extend the `request` definition's `properties` with:

```json
"authorize": {
  "description": "FormRequest::authorize(): bool|Response. A boolean passes through; a string is a PHP reference resolved via Container::call; a map declares one native Gate call — the key is the Illuminate\\Contracts\\Auth\\Access\\Gate method name (check, any, none, allows, denies, inspect, authorize, raw) and the value is a map of that method's native parameter names (ability|abilities, arguments). `arguments` follows Authorize::getGateArguments(): a class-string passes through, a route parameter name resolves to its bound value, a quoted literal unquotes.",
  "oneOf": [
    { "type": "boolean" },
    { "$ref": "#/definitions/reference" },
    {
      "type": "object",
      "minProperties": 1,
      "maxProperties": 1,
      "additionalProperties": {
        "type": "object",
        "additionalProperties": true,
        "properties": {
          "ability": { "type": "string" },
          "abilities": { "oneOf": [{ "type": "string" }, { "type": "array", "items": { "type": "string" } }] },
          "arguments": { "oneOf": [{ "type": "string" }, { "type": "array", "items": { "type": "string" } }] }
        }
      }
    }
  ]
}
```

---

## 4. Manifest examples (host-facing)

```yaml
gate:
  policy:                                            # ≙ Gate::policy($class, $policy)
    App\Models\Post: App\Policies\PostPolicy
    App\Models\Comment: App\Policies\CommentPolicy
  define:                                            # ≙ Gate::define($ability, $callback)
    publish: App\Gates\PublishGate@publish           # instance method, native Class@method resolution

requests:
  - name: post
    authorize:                                       # ≙ Gate::inspect('update', <route-bound {post}>), §2.4 pairing
      inspect:
        ability: update
        arguments: post                              # the route-bound model — native can:update,post semantics
    rules:
      title: [required, string]

  - name: comments
    authorize:                                       # ≙ Gate::check('viewAny', App\Models\Comment) → bool
      check:
        abilities: viewAny
        arguments: App\Models\Comment                # class-string passes through (Authorize::isClassName)
    rules:
      body: [required, string]
```

Equivalent hand-written FormRequest, for the record: `public function authorize(): Response { return Gate::inspect('update', [$this->route('post')])->...; }` — the manifest declares the same native call.

---

## 5. Fixtures and tests

### 5.1 Fixture classes

`tests/Fixtures/App/Policies/PostPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Policies;

use Illuminate\Auth\Access\Response;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models\Post;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing\User;

class PostPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->id === 1 ? Response::allow() : Response::deny('Only user 1 may list posts.');
    }

    public function update(User $user, string $post): bool
    {
        return $post === '1';
    }

    public function attach(User $user, mixed $post = null): bool
    {
        return $post === null || in_array($post, ['5', 5], true);
    }
}
```

`tests/Fixtures/App/Policies/PublishGate.php` (the `define` target — a `Class@method` string callback):

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Policies;

use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing\User;

class PublishGate
{
    public function publish(User $user, string $post): bool
    {
        return $post === '1';
    }
}
```

### 5.2 `tests/Fixtures/manifest/gate.yml` (new)

```yaml
gate:
  policy:
    ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models\Post: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Policies\PostPolicy
  define:
    publish: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Policies\PublishGate@publish
    update: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Policies\PostPolicy@update   # string callback — no model binding on the route (§2.4)
    attach: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Policies\PostPolicy@attach

requests:
  - name: listed
    authorize:
      check:
        abilities: viewAny
        arguments: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models\Post   # policy path — getPolicyFor($arguments[0]) (§1.3)
    rules:
      title: [required, string]

  - name: attached
    authorize:
      check:
        abilities: attach                        # no `arguments` — the array_key_exists early return
    rules:
      title: [required, string]

  - name: denied-listed
    authorize:
      inspect:
        ability: viewAny
        arguments: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models\Post   # class-string pass-through
    rules:
      title: [required, string]

  - name: update-post
    authorize:
      inspect:
        ability: update
        arguments: post                          # route parameter name → its bound value ('1')
    rules:
      title: [required, string]

  - name: publish-post
    authorize:
      inspect:
        ability: publish
        arguments: post                          # a `gate.define`d ability    rules:
      title: [required, string]

  - name: ghost-argument
    authorize:
      inspect:
        ability: attach
        arguments: ghost                         # no such route parameter, unquoted → null
    rules:
      title: [required, string]

  - name: literal-argument
    authorize:
      inspect:
        ability: attach
        arguments: "'5'"                         # quoted literal → '5'
    rules:
      title: [required, string]

  - name: numbered-argument
    authorize:
      inspect:
        ability: attach
        arguments: 5                             # non-string passes through
    rules:
      title: [required, string]

  - name: multi-gate
    authorize:
      check:
        abilities: viewAny
      inspect:
        ability: viewAny                         # two Gate methods → LogicException
    rules:
      title: [required, string]

routes:
  addRoute:
    - uri: listed
      methods: GET
      action: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\RequestController, store]
      middleware: [web]
      metadata:
        request: listed

    - uri: attached
      methods: GET
      action: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\RequestController, store]
      middleware: [web]
      metadata:
        request: attached

    - uri: denied-listed
      methods: GET
      action: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\RequestController, store]
      middleware: [web]
      metadata:
        request: denied-listed

    - uri: "posts/{post}"
      methods: PUT
      action: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\RequestController, store]
      middleware: [web]
      metadata:
        request: update-post

    - uri: "publish/{post}"
      methods: PUT
      action: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\RequestController, store]
      middleware: [web]
      metadata:
        request: publish-post

    - uri: attach
      methods: POST
      action: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\RequestController, store]
      middleware: [web]
      metadata:
        request: ghost-argument

    - uri: literal
      methods: POST
      action: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\RequestController, store]
      middleware: [web]
      metadata:
        request: literal-argument

    - uri: numbered
      methods: POST
      action: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\RequestController, store]
      middleware: [web]
      metadata:
        request: numbered-argument

    - uri: multi
      methods: POST
      action: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\RequestController, store]
      middleware: [web]
      metadata:
        request: multi-gate
```

### 5.3 `tests/Feature/GateRegistrationTest.php` (new)

```php
<?php

declare(strict_types=1);

use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models\Post;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing\User;

$manifest = __DIR__.'/../Fixtures/manifest/gate.yml';

it('binds the declared policy and grants the check for the request user', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);
    $this->actingAs(new User(['id' => 1]));

    $this->getJson('/listed?title=ok')->assertOk()->assertJson(['title' => 'ok']);   // viewAny(User) via gate.policy
});

it('reaches a defined ability with no arguments declared', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);
    $this->actingAs(new User(['id' => 1]));

    $this->getJson('/attached?title=ok')->assertOk();                // attach($user) → null default
});

it('returns the policy Response denial through the native seam', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);
    $this->actingAs(new User(['id' => 2]));

    $this->getJson('/denied-listed?title=ok')
        ->assertForbidden()
        ->assertJson(['message' => 'Only user 1 may list posts.']);   // Response::message via ->authorize()
});

it('resolves a route parameter name to its bound value for the gate arguments', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);
    $this->actingAs(new User(['id' => 1]));

    $this->putJson('/posts/1', ['title' => 'ok'])->assertOk();        // update($user, '1') === true
});

it('authorizes through a gate.define ability reference', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);
    $this->actingAs(new User(['id' => 1]));

    $this->putJson('/publish/1', ['title' => 'ok'])->assertOk();      // PublishGate@publish
});

it('passes a class-string argument through to the policy', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);
    $this->actingAs(new User(['id' => 1]));

    expect(app(\Illuminate\Contracts\Auth\Access\Gate::class)->forUser(new User(['id' => 1]))
        ->check('viewAny', Post::class))->toBeTrue();                  // getPolicyFor(Post::class) → the binding
});

it('resolves an unknown unquoted argument to null', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);
    $this->actingAs(new User(['id' => 1]));

    $this->postJson('/attach', ['title' => 'ok'])->assertOk();        // attach($user, null)
});

it('unquotes a quoted literal argument', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);
    $this->actingAs(new User(['id' => 1]));

    $this->postJson('/literal', ['title' => 'ok'])->assertOk();       // attach($user, '5')
});

it('passes a non-string argument through untouched', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);
    $this->actingAs(new User(['id' => 1]));

    $this->postJson('/numbered', ['title' => 'ok'])->assertOk();      // attach($user, 5)
});

it('rejects an authorize map that declares more than one Gate method', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);
    $this->actingAs(new User(['id' => 1]));
    $this->withoutExceptionHandling();

    expect(fn () => $this->postJson('/multi', ['title' => 'ok']))
        ->toThrow(LogicException::class);
});
```

(The last test also asserts the native mismatch failure path is not masked: a request declaring `abilities:` on `inspect` would throw `BindingResolutionException` before any assertion could pass — covered by the dynamic dispatch failing with Laravel's own exception; add an optional expectation if coverage demands the line.)

### 5.4 Coverage map (Definition-of-Done §6 100% gate)

| New line(s) | Covered by |
|---|---|
| `GateDeclarationServiceProvider::boot` guard | every existing test boots a manifest without `gate:` (same as the other provider guards) |
| `callAfterResolving` + both `foreach` loops | every `gate.yml` request test (Gate resolves during the request, policies/abilities land first) |
| `Request::$authorize` union hydration | gate.yml entries |
| `authorize()` `null → true` / bool / ref paths | unchanged — existing `DeclaredRequestTest` coverage |
| `authorize()` `is_array` arm + `gateCall` (count guard, `make`+`forUser`, `Container::call`) | tests 1–5, 10 |
| `arguments` `array_key_exists` early return / scalar / list | test 2 (early), 3–5 and 7–9 (scalar); list covered by the `arguments: [a, b]` variant if added — otherwise the list branch needs one more test: add `attach` with `arguments: [ghost, "'5'"]` asserting ok |
| `gateArgument` branches | non-string (test 9), class-string (test 6 via `check('viewAny', Post::class)` + test 3), route param (test 4), unknown → null (test 7), quoted literal (test 8) |

---

## 6. Documentation updates

1. **New `docs/declarative-gate.md`** — this document, restated as the shipped spec once implemented (the `gate:` block, the `authorize` map form, the `Authorize::getGateArguments()` contract, denied-semantics table).
2. **`docs/declarative-requests.md`** — §2.2 value-or-reference table: add the map form row; §2.4 `authorize` row: widen the value shape (`bool | reference | Gate-call map`); new §2.7 "The `authorize` Gate call" with the §4 example.
3. **`docs/declarative-tier1-gap-inventory.md`** — §1 row 7: `[/]` (narrower) → `[x]`, verified column "`authorize` map form dispatches onto the native `Gate` contract via `Container::call`; `gate:` `policy`/`define` shipped"; §3 correction 6 rewritten; §4 remediation list gains the closed item.
4. **`docs/declarative-framework-api-mapping.md`** — Domain 5 seam row `[/]` → `[x]` ("policy-based authorization hooks shipped via the `gate:` block — declarative-gate.md"); Domain 9 `gate:` row `[ ]` → `[/]` ("`define`, `policy` mapped; `before`, `after`, `resource`, `allowIf`/`denyIf` remain"); §1.7 annotated as resolved-with-remainder.
5. **`docs/declarative-request-to-view-roadmap.md`** — stage table gains row 25: `Declarative Gate & Policy Bindings | Illuminate\Contracts\Auth\Access\Gate | gate | Completed`; §3.1 bullet for the gate block and the seam authorize map form.
6. **`README.md`** — a `## Gate` section after `## Requests` with the §4 example and the `authorize` shape table.

---

## 7. Execution order & acceptance criteria

1. `src/Gate.php`, `src/Providers/GateDeclarationServiceProvider.php`, `src/Manifest.php`, `src/DefaultProviders.php` — the `gate:` block.
2. `src/Request.php` + `src/DeclaredRequest.php` — the seam map form.
3. `manifest.schema.json` — schema surface.
4. Fixtures (`PostPolicy`, `PublishGate`, `gate.yml`) + `tests/Feature/GateRegistrationTest.php`.
5. Docs (§6), then run `composer check` (pint, rector --dry-run, phpstan, pest --no-tia --coverage --min=100).

Acceptance: row 7 reclassifies `[/]` (narrower) → `[x]`; a declared request authorizes against a bound policy and a defined ability with zero hand-written seam PHP; `composer check` passes (the pre-existing coverage regression recorded in the inventory §5.6 must be remediated first or concurrently — it is unrelated to this row).

## 8. Non-goals and risks

- **Non-goals**: `Gate::before`/`after`/`resource`/`allowIf`/`denyIf`/`guessPolicyNamesUsing` (remain in the `gate:` Tier 1 row); `#[UsePolicy]` on synthesized `DeclaredModel` classes (Phase 2); route `can:` middleware plumbing (already native via the `builders.can` dispatch); DeclaredAction/DeclaredView authorization (they consume the same seam via `metadata.request`).
- **Risk — policy guessing shadows a typo'd binding**: `getPolicyFor()` falls back to guessed conventional names, so a mis-spelled `gate.policy` key may silently resolve to a guessed class. Mitigation: tests bind fixture classes whose namespace does not match the guess candidates; documented behavior, not a Rule 7 violation (Laravel's own resolution order).
- **Risk — `check` shares one `$arguments` across abilities** (native, `Gate.php:350`): a multi-ability `abilities` list feeds the same argument to every policy method; the doc states the native semantics instead of inventing per-ability arguments.
- **Risk — dynamic callable and phpstan level 9**: `$this->container->call([$Gate, $method], …)` may need the `@phpstan-ignore` annotation noted in §3.6; the alternative (positional spread) would hardcode parameter order and was rejected.