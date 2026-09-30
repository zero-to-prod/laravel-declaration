# Declarative Validation Factory Extensions — the `validator:` Tier 1 Block & Conditional Rules in `requests.rules`

Source of truth: `vendor/laravel/framework/src/Illuminate/Validation/Factory.php`, `Validator.php`, `Concerns/FormatsMessages.php`, `Rule.php`, `ConditionalRules.php`, `ValidationRuleParser.php`, `ValidationServiceProvider.php`, and `Contracts/Validation/Factory.php` (`laravel/framework` v13.33.0), verified by direct reflection and two empirical harness runs. This document closes [declarative-tier1-gap-inventory.md](declarative-tier1-gap-inventory.md) §2.4 — the remaining gap behind `§1 row 6 — Form Request Declaration, requests: [/] (narrower)`, where `shouldFailOnUnknownFields` is already native-named and rule class-strings already container-resolve.

Two native surfaces remain unmapped:

1. **The `validator:` Tier 1 block** — the four factory-wide extension registries of `Illuminate\Validation\Factory` (`extend`, `extendImplicit`, `extendDependent`, `replacer`). Today a custom regex/semantic rule usable across every declared request requires a hand-written PHP service provider. These belong in a new `ValidatorDeclarationServiceProvider` applied when Laravel first resolves the shared factory.
2. **Conditional rules in `requests.rules`** — `Illuminate\Validation\Rule::when()` / `Rule::unless()` returning `Illuminate\Validation\ConditionalRules`, consumed by `ValidationRuleParser::filterConditionalRules()`. Data-dependent rule lists cannot be declared because the rule position today accepts only strings that pass through untouched.

On implementation: gap inventory §2.4 closes, inventory §1 row 6 reclassifies `[/]` → `[x]`, and the audit's Domain 5 rows **Form Request Declaration** and **Validation Factory & Custom Rules (`validator:`)** reclassify to `[x]`.

---

## 1. Public API of `Illuminate\Validation\Factory` (the registry surface)

### 1.1 Lifecycle position (when each call runs)

```
boot(): ValidatorDeclarationServiceProvider::boot()
  ├─ guard: the manifest declares no `validator:` block → return (no factory resolution is forced)
  └─ callAfterResolving('validator', apply)                  ← queued; fires on FIRST factory resolution

first validation anywhere in the process:
  ValidationServiceProvider::registerValidationFactory()      ← DeferrableProvider; singleton 'validator'
  → our `apply` callback runs BEFORE the caller receives the factory instance
  → extensions land on the shared Factory instance

per request: DeclaredRequest::validateResolved() (ValidatesWhenResolvedTrait)
  → getValidatorInstance() → validator() → createDefaultValidator()   (FormRequest.php:171)
  → ValidationFactory::make($data, $rules, $messages, $attributes)
      └─ addExtensions($validator): the factory copies its five registries into the
         validator instance at make() time                    (Factory.php:171-185)
  → Validator::setRules → addRules → filterConditionalRules   (Validator.php:1315)
      └─ ConditionalRules resolve HERE, against the validator's data
  → FormRequest::validateNoUnknownFields(), queued as a validator `after` hook
      (FormRequest.php:121-124) — re-reads `validationRules()` (FormRequest.php:205),
      so `rules()` — and every condition reference inside it — runs twice per request
```

Three timing facts drive the design:

1. **The factory is a per-process singleton** (`'validator'`, `ValidationServiceProvider.php:29-42`). Extensions registered once are visible to every later `Factory::make()` — including every `DeclaredRequest`, every `$request->validate()` macro call, and every `Validator::make()` in application code. There is nothing per-request to re-register.
2. **`ValidationServiceProvider` is a `DeferrableProvider`** — nothing resolves `'validator'` during boot, so `callAfterResolving('validator', …)` registers the callback lazily and the factory is never constructed at boot unless the host asks for it. This is the exact lifecycle `ViewDeclarationServiceProvider` uses for `'view'` (`ViewDeclarationServiceProvider.php:16`), where the suite proves the factory stays unresolved until first use (`ViewRegistrationTest`, "applies the block when Laravel first resolves the view factory").
3. **`callAfterResolving` fires the callback immediately if the factory was already resolved** (`ServiceProvider::callAfterResolving`, `Support/ServiceProvider.php:310`), so a host that builds validators mid-boot still gets the extensions. Both orderings are safe; the seam holds no state.

### 1.2 The four registry methods (native signatures, v13.33.0)

v13.33.0 declares **no native parameter or return types** on these methods — the signatures below add the docblock types; every method returns `void`.

| Native method | Signature (docblock-typed) | Registry it fills | Line |
|---|---|---|---|
| `Factory::extend()` | `extend($rule, $extension, $message = null)` — `string`, `Closure\|string`, `?string` | `$extensions` | `Factory.php:195` |
| `Factory::extendImplicit()` | `extendImplicit($rule, $extension, $message = null)` — same types | `$implicitExtensions` | `Factory.php:212` |
| `Factory::extendDependent()` | `extendDependent($rule, $extension, $message = null)` — same types | `$dependentExtensions` | `Factory.php:229` |
| `Factory::replacer()` | `replacer($rule, $replacer)` — `string`, `Closure\|string` | `$replacers` | `Factory.php:245` |

The `$message` argument of the three `extend*` methods is the **only** native path to factory-wide fallback messages: it writes `$this->fallbackMessages[Str::snake($rule)] = $message` (`Factory.php:199-201`), applied to every validator in `addExtensions()` via `setFallbackMessages()` (`Factory.php:184`). `Factory` exposes no other public setter for fallback messages.

**Contract coverage.** `Illuminate\Contracts\Validation\Factory` declares `extend` (line 26), `extendImplicit` (line 36) and `replacer` (line 45) — but **not** `extendDependent`. The provider's callback therefore type-hints the concrete `Illuminate\Validation\Factory`, which is exactly what the `'validator'` alias resolves (`ValidationServiceProvider.php:32` constructs the concrete class; `Application.php:1684` aliases both `Illuminate\Validation\Factory` and `Illuminate\Contracts\Validation\Factory` to `'validator'`).

### 1.3 How the extension string dispatches (all of it is Laravel's own code)

**Registration → per-validator copy.** `Factory::make()` copies the five registries into each validator via `addExtensions()` (`Factory.php:171-185`): `Validator::addExtensions()` snake-cases the keys (`Validator.php:1392-1401`), `addImplicitExtensions()` also appends `Str::studly($rule)` to `implicitRules` (`Validator.php:1409-1416`), `addDependentExtensions()` appends to `dependentRules` (`Validator.php:1424-1431`), `addReplacers()` snake-cases keys (`Validator.php:1479-1488`).

**Invocation.** `Validator::validateAttribute()` calls `$this->$method($attribute, $value, $parameters, $this)` — the four positional arguments every extension receives (`Validator.php:733`). The dynamic `validate*` name lands in `Validator::__call()`, which snake-cases the suffix and looks the registry up (`Validator.php:1784-1795`), then `callExtension()`:

```php
// Validator.php:1706-1716
protected function callExtension($rule, $parameters)
{
    $callback = $this->extensions[$rule];

    if (is_callable($callback)) {
        return $callback(...array_values($parameters));          // Closure or plain function
    } elseif (is_string($callback)) {
        return $this->callClassBasedExtension($callback, $parameters);
    }
}

// Validator.php:1724-1730
protected function callClassBasedExtension($callback, $parameters)
{
    [$class, $method] = Str::parseCallback($callback, 'validate');   // default method: `validate`

    return $this->container->make($class)->{$method}(...array_values($parameters));
}
```

So a **string reference passes through untouched** and Laravel resolves all three forms itself:

| Reference string | Laravel's dispatch |
|---|---|
| `App\Validators\Uppercase@check` | `Str::parseCallback(…, 'validate')` → `make()` + `check(...)` |
| `App\Validators\Uppercase` | `make()` + the **default** `validate(...)` method |
| `App\Validators\Uppercase@__invoke` | `make()` + `__invoke(...)` (the invokable form, explicitly) |
| `App\Validation\is_uppercase` | `is_callable($callback)` → the namespaced function, called positionally |

**Replacers.** Message replacement runs in `FormatsMessages::makeReplacements()` (`FormatsMessages.php:249-267`), which consults `$this->replacers[Str::snake($rule)]` **before** the built-in `replace{Rule}` methods. `callReplacer()` invokes a `Closure` positionally with `($message, $attribute, $rule, $parameters, $validator)`; a string goes through `callClassBasedReplacer()` — `Str::parseCallback($callback, 'replace')`, so a bare class-string resolves to the **default** method `replace(...)` (`FormatsMessages.php:563-591`).

**Rule-name normalization.** The parsed rule name is always `Str::studly()`'d upstream (`ValidationRuleParser.php:280,302`), registry keys are `Str::snake()`'d on both sides (`Validator.php:1442,1499`; `Factory.php:200`), and PHP method calls are case-insensitive — so YAML keys may be written snake_case or camelCase and match identically (verified empirically: rule string `phone`, extension key `phone`, registry key `phone`; rule string `requiredWhenRole`, registry key `required_when_role`).

**Message resolution order** for a failing custom rule: per-request custom messages (`field.rule`) → lang lines (`validation.custom.<attribute>.<rule>`, `validation.<rule>`) → the factory fallback message from `extend*`'s `$message` → otherwise the **raw lang key** is rendered as the message (verified: an extension with no declared message yields `validation.phone`). Declare `message:` (or per-request `messages:`) for user-facing text.

### 1.4 Conditional rules — `Rule::when()` / `Rule::unless()` / `ConditionalRules`

| Native API | Signature | Line |
|---|---|---|
| `Illuminate\Validation\Rule::when()` | `when($condition, $rules, $defaultRules = []): ConditionalRules` (`callable\|bool $condition`) | `Rule.php:54` |
| `Illuminate\Validation\Rule::unless()` | `unless($condition, $rules, $defaultRules = []): ConditionalRules` | `Rule.php:67` |
| `Illuminate\Validation\ConditionalRules::__construct()` | `($condition, $rules, $defaultRules = [])` | `ConditionalRules.php:37` |
| `ConditionalRules::passes($data)` | `callable` condition → `call_user_func($condition, new Fluent($data))`; `bool` → as-is | `ConditionalRules.php:50-55` |
| `ConditionalRules::rules($data)` / `defaultRules($data)` | `string` → `explode('|', …)`; otherwise `value($this->rules, new Fluent($data))` | `ConditionalRules.php:63-81` |

Consumption happens at **validator construction**: `Validator::addRules()` wraps the rule array in `ValidationRuleParser::filterConditionalRules($rules, $this->data)` (`Validator.php:1315`), which replaces every `ConditionalRules` — as a whole field value **or** as an entry inside a field's rule list — with the resolved rule list (`ValidationRuleParser.php:350-372`). Two consequences:

- `Rule::unless($condition, $rules, $defaultRules)` internally constructs `new ConditionalRules($condition, $defaultRules, $rules)` — the **author-facing semantics of `Rule::unless` are "`$rules` apply when the condition is falsy"** — and a manifest entry maps onto the native method as-is, with no swap logic at the seam.
- A `callable` condition is evaluated **lazily** by `ConditionalRules::passes()` at set-rules time with the validator's data wrapped in `Illuminate\Support\Fluent`. A `bool` condition is evaluated at rule-construction time by whoever builds the rule array.

### 1.5 What each inventory row resolves to

| Gap inventory §2.4 row | Resolution |
|---|---|
| `Factory::extend()` | `validator.extend` — map of `rule` → extension reference; `message` via the map form (§2.2) |
| `Factory::extendImplicit()` | `validator.extendImplicit` — same shape |
| `Factory::extendDependent()` | `validator.extendDependent` — same shape |
| `Factory::replacer()` | `validator.replacer` — map of `rule` → replacer reference |
| Conditional rules (`Rule::when`/`unless`) | `requests.rules.<field>[].when` / `.unless` — entries whose keys are the native `Rule` factory method names (§2.6) |

---

## 2. Manifest schema proposal

### 2.1 Design rule (`validator:`)

> **Every key in the `validator:` block is an `Illuminate\Validation\Factory` registry-method name (Rule 1). Its value is the registry content: a map whose key is the native first argument (`$rule`) and whose value is the native second argument (`$extension` / `$replacer`) — or, for the three `extend*` methods, a map of native parameter names when the optional `$message` argument is declared (Rule 2: references and scalars pass through untouched).** The block applies when Laravel first resolves the shared factory, before any validator is built.

No invented verbs, no per-rule resolution glue: a declared reference **is** the native `Closure|string` argument, and Laravel's own `callClassBasedExtension` / `callClassBasedReplacer` dispatch it. The provider is two loops over four keys — zero per-method code.

### 2.2 Values

| Declared value | Dispatch | Native call |
|---|---|---|
| `extend: {<rule>: '<ref>'}` | extension declared, message omitted | `$Factory->extend('<rule>', '<ref>')` |
| `extend: {<rule>: {extension: '<ref>', message: '…'}}` | both arguments | `$Factory->extend('<rule>', '<ref>', '…')` — `extension`/`message` are the native parameter names |
| `extendImplicit` / `extendDependent` | same two shapes as `extend` | `$Factory->extendImplicit(...)` / `$Factory->extendDependent(...)` |
| `replacer: {<rule>: '<ref>'}` | replacer reference (no `$message` exists on the native method) | `$Factory->replacer('<rule>', '<ref>')` |

YAML cannot express a `Closure`, so only the string forms of the native `Closure|string` arguments are declarable — and the string forms are fully native (§1.3). There is no package-side resolution layer to maintain.

### 2.3 Full example (`tests/Fixtures/manifest/validator.yml`)

```yaml
# yaml-language-server: $schema=./../../../manifest.schema.json

validator:                                                # ——— Tier 1: Factory registry methods ———
  extend:                                                 # -> extend($rule, $extension, $message = null)
    uppercase: 'ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Validation\Uppercase@check'
    slug:                                                 # the optional $message via the native parameter names
      extension: 'ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Validation\SlugExtension@check'
      message: 'The :attribute must be a slug.'
  extendImplicit:                                         # runs even when the field is absent/empty
    phone:
      extension: 'ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Validation\Phone'
  extendDependent:                                        # parameters may reference other fields
    guardedMin: 'ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Validation\GuardedMin@check'
  replacer:                                               # -> replacer($rule, $replacer); default method `replace`
    uppercase: 'ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Validation\Uppercase@replace'

requests:
  - name: profile
    messages:
      name.uppercase: The :attribute must be uppercase (min :min).   # the replacer fills :min
    rules:                                                # ——— conditional rules (§2.6) ———
      name: [required, string, uppercase:8]               # plain rule strings resolve through the factory
      slug: [required, slug]
      phone: phone                                        # implicit: fires even when `phone` is absent
      secret: 'guardedMin:reveal,8'                       # dependent: $parameters reference other fields
      email:
        - when:                                           # ≙ Rule::when($condition, $rules, $defaultRules)
            condition: 'ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Validation\IsTeamAdmin'
            rules: [required, email]
            defaultRules: [nullable, email]
      coupon:
        - nullable
        - when:
            condition: 'ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Validation\ProTier'   # returns a Closure
            rules: 'in:pro,elite'                         # pipe string: passes through, exploded natively
      discount:
        - unless:                                         # ≙ Rule::unless(...) — $rules apply when falsy
            condition: 'ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Validation\IsTeamAdmin'
            rules: [prohibited]
      notes:                                              # whole-field form: the field value IS Rule::when(...)
        when:
          condition: false                                # bool conditions pass through untouched
          rules: [required, string]

routes:
  addRoute:
    - uri: profile
      methods: POST
      action: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\RequestController, store]
      metadata:
        request: profile
```

The PHP the references point at — one ordinary class per concern, nothing extends a package type:

```php
namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Validation;

use Illuminate\Contracts\Validation\Validator;

/** extend: `uppercase` — invoked positionally as check($attribute, $value, $parameters, $validator). */
final class Uppercase
{
    public function check(string $attribute, mixed $value, array $parameters, Validator $validator): bool
    {
        return ctype_upper((string) $value);
    }

    /** replacer: a bare class-string resolves to `replace` (Str::parseCallback($callback, 'replace')). */
    public function replace(string $message, string $attribute, string $rule, array $parameters, Validator $validator): string
    {
        return str_replace(':min', $parameters[0] ?? '?', $message);
    }
}

final class SlugExtension
{
    public function check(string $attribute, mixed $value, array $parameters, Validator $validator): bool
    {
        return preg_match('/^[a-z0-9-]+$/', (string) $value) === 1;
    }
}

/** extendImplicit: a bare class-string resolves to the default `validate` method. */
final class Phone
{
    public function validate(string $attribute, mixed $value, array $parameters, Validator $validator): bool
    {
        return is_string($value) && preg_match('/^\+?[0-9]{7,15}$/', $value) === 1;
    }
}

/** extendDependent: the parsed rule lands in dependentRules, so parameters may reference other fields. */
final class GuardedMin
{
    public function check(string $attribute, mixed $value, array $parameters, Validator $validator): bool
    {
        $data = $validator->getData();

        return ! isset($data[$parameters[0]]) || mb_strlen((string) $value) >= (int) $parameters[1];
    }
}
```

```php
use Closure;
use Illuminate\Support\Fluent;
use ZeroToProd\LaravelDeclaration\DeclaredRequest;

/** condition reference returning bool — evaluated eagerly, once per request, with $request injected. */
final class IsTeamAdmin
{
    public function __invoke(DeclaredRequest $request): bool
    {
        return $request->boolean('admin');
    }
}

/** condition reference returning a Closure — passed through to ConditionalRules, which calls it
 *  natively with Fluent($data) at validator construction. */
final class ProTier
{
    public function __invoke(DeclaredRequest $request): Closure
    {
        return fn (Fluent $data): bool => ($data->get('tier') ?? null) === 'pro';
    }
}
```

### 2.4 Key → method → signature map

| YAML key | `Factory` method | Value shape | Absent → |
|---|---|---|---|
| `extend` | `extend(string $rule, Closure\|string $extension, ?string $message = null)` | `map<rule, ref \| {extension, message?}>` | `[]` — no call |
| `extendImplicit` | `extendImplicit(string $rule, Closure\|string $extension, ?string $message = null)` | same as `extend` | `[]` — no call |
| `extendDependent` | `extendDependent(string $rule, Closure\|string $extension, ?string $message = null)` | same as `extend` | `[]` — no call |
| `replacer` | `replacer(string $rule, Closure\|string $replacer)` | `map<rule, ref>` | `[]` — no call |

### 2.5 Registration algorithm (the Tier 1 provider)

One DataModel and one provider — keys are `Factory` method names (Rule 1), values are the native arguments (Rule 2):

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class Validator
{
    use DataModel;

    public const string extend = 'extend';

    /** @var array<string, string|array{extension: string, message?: string}> */
    #[Describe([Describe::default => []])]
    public array $extend;

    public const string extendImplicit = 'extendImplicit';

    /** @var array<string, string|array{extension: string, message?: string}> */
    #[Describe([Describe::default => []])]
    public array $extendImplicit;

    public const string extendDependent = 'extendDependent';

    /** @var array<string, string|array{extension: string, message?: string}> */
    #[Describe([Describe::default => []])]
    public array $extendDependent;

    public const string replacer = 'replacer';

    /** @var array<string, string> */
    #[Describe([Describe::default => []])]
    public array $replacer;

    /** The native parameter names of `extend*`'s map form. */
    public const string extension = 'extension';

    public const string message = 'message';
}
```

`Manifest.php` gains one nullable property, adjacent to the subsystem it serves:

```php
public const string validator = 'validator';

#[Describe([Describe::nullable => true])]
public ?Validator $validator;
```

The provider applies the block when Laravel first resolves the shared factory — the `view:` lifecycle, `callAfterResolving`. One loop dispatches dynamically: every property name **is** the native `Factory` method name (Rule 1), and the declared value **is** the native argument list (Rule 2) — `$Factory->{$method}(...)` is the entire seam:

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Factory;
use ZeroToProd\LaravelDeclaration\Manifest;
use ZeroToProd\LaravelDeclaration\Validator;

/** @internal */
class ValidatorDeclarationServiceProvider extends ServiceProvider
{
    public function boot(?Manifest $Manifest = null): void
    {
        if (! $Manifest?->validator instanceof Validator) {
            return;
        }

        $this->callAfterResolving('validator', function (Factory $Factory) use ($Manifest): void {
            foreach ($Manifest->validator->toArray() as $method => $registry) {
                foreach ($registry as $rule => $extension) {
                    $Factory->{$method}($rule, ...$this->arguments($extension));   // native argument order
                }
            }
        });
    }

    /** The native second argument onward: a reference string, or the map form's {extension, message}.
     *  `replacer()` takes no `$message`, so its values are always reference strings (§3.6 schema). */
    private function arguments(string|array $extension): array
    {
        return is_string($extension)
            ? [$extension]
            : [$extension[Validator::extension], $extension[Validator::message] ?? null];
    }
}
```

`DefaultProviders` inserts the provider between `PaginationDeclarationServiceProvider` and `DatabaseDeclarationServiceProvider`. The slot is not load-bearing — extensions apply at the first factory resolution, which precedes any request validation — it simply groups the provider with the other registry-applying ones. `config/laravel-declaration.php` needs no change (its `providers` default calls `defaultProviders()` dynamically).

### 2.6 Conditional rules in `requests.rules`

> **A rule entry keyed `when` / `unless` is the native `Illuminate\Validation\Rule` factory method name (Rule 1). Its value is a map of that method's native parameter names — `condition`, `rules`, `defaultRules` (Rule 2). `DeclaredRequest` calls `Rule::when()` / `Rule::unless()` with the declared arguments; every other rule entry passes through exactly as before.**

| Declared | Dispatch | Native call |
|---|---|---|
| `{when: {condition: true, rules: …}}` | `bool` passes through | `Rule::when(true, …)` |
| `{when: {condition: '<ref>', rules: …}}` | `Container::call('<ref>', ['request' => $request])` — the §2.2 reference convention | `Rule::when($returned, …)` — `bool` **or** `callable`, both native |
| `{unless: {condition: …, rules: …, defaultRules: …}}` | same, onto `Rule::unless` — `$rules` apply when the condition is **falsy** (native semantics; no swap at the seam) | `Rule::unless($condition, $rules, $defaultRules)` |
| `rules` / `defaultRules` | pipe `string` → passes through (`ConditionalRules::rules()` explodes it natively); `list` → each entry resolves through `rule()` (class refs container-made; nested conditionals recurse) | the native `array\|string` arguments |
| whole field value (`notes:` above) | the field value **is** the conditional map | `filterConditionalRules()` accepts a bare `ConditionalRules` as the field value |

Ordering: `rules()` runs inside `validateResolved()` — after `prepareForValidation()` merges input — so a condition reference sees the same post-merge state the native `Fluent($data)` path sees (`validationData()` feeds the validator). A reference returning a `Closure` keeps the fully-native lazy semantics: `ConditionalRules::passes()` calls it with `new Fluent($data)` at validator construction. One repetition is native Laravel, not this seam: `validateNoUnknownFields()` re-reads `validationRules()` from its `after` hook (`FormRequest.php:121-124,205`), so a resolved condition reference is container-called **twice per request** — once to build the validator, once for the unknown-field check. Declared `bool` conditions are stable across both passes; a closure-returning reference must be pure (both passes return equivalent closures, and only the first is consumed by `ConditionalRules`).

`DeclaredRequest` changes (§3.5) — the conditional branch dispatches dynamically on the entry's key, and `rules()` normalizes the whole-field form:

```php
/** @return array<string, mixed> */
public function rules(): array
{
    /** @var array<string, mixed> $rules */
    $rules = $this->resolve($this->declaration()->rules);

    return array_map(
        fn (mixed $field_rules): mixed => is_array($field_rules) && array_is_list($field_rules)
            ? array_map($this->rule(...), $field_rules)   // list of rule entries — one call per entry
            : $this->rule($field_rules),                  // a conditional map IS the field value (Rule::when)
        $rules,
    );
}

/** A rule entry keyed `when`/`unless` is conditional; otherwise a reference only when its rule
 *  name (before the first `:`) is namespaced (declarative-requests.md §2.2). */
private function rule(mixed $rule): mixed
{
    if (is_array($rule) && (isset($rule[self::when]) || isset($rule[self::unless]))) {
        return $this->conditionalRule($rule);
    }

    if (! is_string($rule) || ! str_contains(Str::before($rule, ':'), '\\')) {
        return $rule;
    }

    return class_exists($rule) ? $this->container->make($rule) : $this->resolve($rule);
}

/** @param  array<mixed>  $rule */
private function conditionalRule(array $rule): mixed
{
    if (isset($rule[self::when], $rule[self::unless])) {
        throw new LogicException('A rule entry declares both `when` and `unless`; declare one.');
    }

    $method = isset($rule[self::when]) ? self::when : self::unless;
    $declaration = $rule[$method];

    if (! is_array($declaration) || ! array_key_exists(self::condition, $declaration)) {
        throw new LogicException("The `{$method}` rule entry declares no `condition`.");
    }

    return Rule::{$method}(
        $this->resolve($declaration[self::condition]),                                  // bool | bool|callable
        $this->conditionalRules($declaration[self::rules] ?? []),
        $this->conditionalRules($declaration[self::defaultRules] ?? []),
    );
}

/** The native $rules/$defaultRules argument: a pipe string passes untouched, list entries resolve per rule. */
private function conditionalRules(mixed $rules): mixed
{
    return is_string($rules) ? $rules : array_map($this->rule(...), (array) $rules);
}
```

with the entry keys declared once at the top of the class:

```php
/** The native `Illuminate\Validation\Rule` factory method names and parameter names (declarative-validator.md §2.6). */
private const string when = 'when';

private const string unless = 'unless';

private const string condition = 'condition';

private const string defaultRules = 'defaultRules';
```

### 2.7 Notes / non-goals

- **No resolution layer.** Extension and replacer references pass through untouched; Laravel's `callClassBasedExtension` (default method `validate`), `callClassBasedReplacer` (default method `replace`) and the `is_callable` function branch do the dispatch. Failures are Laravel's own: a reference naming a missing method fails at the first failing message/validation with PHP's `Error`; a rule string with no registered extension throws `BadMethodCallException` (`Validator.php:1784-1795`).
- **`extendDependent` is not `extendImplicit`.** Dependent extensions are validated like ordinary rules — an absent field is skipped (`isValidatable()` → `presentOrRuleIsImplicit()` consults only `implicitRules` for the presence bypass, verified empirically). `dependentRules`' native effect is `dependsOnOtherFields()` → dot-in-parameters rewriting for array-wildcard attributes (`Validator.php:744-757,697-706`). Declare `extendImplicit` when the rule must run even on absent/empty fields.
- **Messages.** The `message:` map form feeds `Factory::$fallbackMessages` and wins whenever no lang line matches; with neither, the raw key (`validation.<snake-rule>`) renders as the message (verified). Per-request `messages:` still override, as anywhere in Laravel.
- **Statelessness (Rule 4).** The manifest holds strings only; references resolve per validation call; extensions register once per process on the singleton. `route:cache`/`config:cache`/Octane-safe. The factory's registries are per-process application configuration applied at first factory resolution — the same posture as `pagination:`, which writes its `Paginator` defaults in `boot()` (both are process-wide, resolved once).
- **Unknown keys.** `DataModel` drops keys it has no property for (`validator: {bogus: …}` hydrates without them — verified empirically against `Pagination::from()`); the JSON schema keeps `additionalProperties: false` for the block. `replacer` values are schema-restricted to reference strings (`laravel-declaration:validate` rejects anything else), so the dynamic `$Factory->{$method}(...)` loop never passes a third argument to `replacer()`.
- **Non-goals.** `Factory::includeUnvalidatedArrayKeys()` (a flag, not a registry — out of the §2.4 table), presence verifiers, `Validator::flushState()` (runtime plumbing), single-rule conditionals (`Rule::excludeIf`, `required_if:…` — already expressible as plain rule strings), and conditional rules on the `validator:` block itself (conditionals belong to rule arrays).

---

## 3. Implementation plan

Complete code, file by file. Nothing below is implemented yet.

### 3.1 `src/Validator.php` — new DataModel (whole file, §2.5)

As written in §2.5.

### 3.2 `src/Manifest.php` — one property

Insert after the `requests` property (mirror order in `manifest.schema.json`):

```php
public const string validator = 'validator';

#[Describe([Describe::nullable => true])]
public ?Validator $validator;
```

### 3.3 `src/Providers/ValidatorDeclarationServiceProvider.php` — new provider (whole file, §2.5)

As written in §2.5.

### 3.4 `src/DefaultProviders.php` — one insertion

```php
            PaginationDeclarationServiceProvider::class,
            ValidatorDeclarationServiceProvider::class,     // <- insert here
            DatabaseDeclarationServiceProvider::class,
```

plus the `use ZeroToProd\LaravelDeclaration\Providers\ValidatorDeclarationServiceProvider;` import.

### 3.5 `src/DeclaredRequest.php` — conditional rules

Four class constants (§2.6), and the four members — `rules()` (replaced), `rule()` (replaced), `conditionalRule()` and `conditionalRules()` (added), plus `use Illuminate\Validation\Rule;`. Full member bodies in §2.6.

### 3.6 `manifest.schema.json` — the block definition

Top-level `properties` gains, after `requests`:

```json
"validator": {
  "$ref": "#/definitions/validator"
}
```

`definitions` gains:

```json
"validator": {
  "description": "Illuminate\\Validation\\Factory registry methods: every key is a method name, its value the registry content. Applied when Laravel first resolves `validator` (callAfterResolving, queued in boot()). An unknown key throws no error (DataModel drops it) but fails this schema.",
  "type": ["object", "null"],
  "additionalProperties": false,
  "properties": {
    "extend": {
      "description": "-> extend($rule, $extension, $message = null), one call per entry: the key is the rule name, the value the extension reference string or a map of the native parameter names {extension, message}.",
      "type": "object",
      "additionalProperties": {"$ref": "#/definitions/extension"}
    },
    "extendImplicit": {
      "description": "-> extendImplicit($rule, $extension, $message = null): the rule runs even when the field is absent or empty.",
      "type": "object",
      "additionalProperties": {"$ref": "#/definitions/extension"}
    },
    "extendDependent": {
      "description": "-> extendDependent($rule, $extension, $message = null): the parsed rule lands in dependentRules, so its parameters may reference other fields.",
      "type": "object",
      "additionalProperties": {"$ref": "#/definitions/extension"}
    },
    "replacer": {
      "description": "-> replacer($rule, $replacer), one call per entry: the value replaces message placeholders; a bare class-string resolves to its `replace` method.",
      "type": "object",
      "additionalProperties": {"$ref": "#/definitions/reference"}
    }
  }
},
"extension": {
  "oneOf": [
    {"$ref": "#/definitions/reference"},
    {
      "type": "object",
      "additionalProperties": false,
      "required": ["extension"],
      "properties": {
        "extension": {"$ref": "#/definitions/reference"},
        "message": {"type": "string", "description": "The factory-wide fallback message (Factory::$fallbackMessages)."}
      }
    }
  ]
}
```

The `request` definition stays as-is (`additionalProperties: true`) — conditional maps are shapes inside the free-form `rules` value.

### 3.7 `tests/Fixtures/manifest/validator.yml` — new fixture (whole file, §2.3)

As written in §2.3.

### 3.8 Fixture classes — new directory `tests/Fixtures/App/Validation/`

Whole files as written in §2.3: `Uppercase.php`, `SlugExtension.php`, `Phone.php`, `GuardedMin.php`, `IsTeamAdmin.php`, `ProTier.php`. The `handle` conditional rule reuses the existing `Tests\Fixtures\App\Requests\Rules\Slug` `ValidationRule` class (container-made through `rule()`), demonstrating nested rule references inside conditional rules.

### 3.9 `tests/Feature/ValidatorRegistrationTest.php` — new test file

```php
<?php

declare(strict_types=1);

use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Validation\Rule;
use ZeroToProd\LaravelDeclaration\Manifest;
use ZeroToProd\LaravelDeclaration\Validator;

$manifest = __DIR__.'/../Fixtures/manifest/validator.yml';

it('registers the declared extensions on the shared validation factory', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $validator = app(ValidationFactory::class)->make(['slug' => 'Not OK'], ['slug' => 'slug']);

    $validator->passes();

    expect($validator->errors()->all())->toBe(['The slug must be a slug.']);   // the Factory::extend $message fallback
});

it('dispatches a Class@method extension through its declared replacer', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $response = $this->postJson('/profile', ['name' => 'john doe', 'slug' => 'ok', 'phone' => '+15551234567']);

    $response->assertStatus(422)->assertJsonValidationErrors(['name']);

    expect($response->json('errors.name.0'))->toBe('The name must be uppercase (min 8).');   // replacer filled :min
});

it('runs an implicit extension when the field is absent', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->postJson('/profile', ['name' => 'JOHN', 'slug' => 'ok'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['phone']);          // a plain extend() would skip the absent field
});

it('passes a dependent extension its rule parameters', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->postJson('/profile', ['secret' => 'abc', 'reveal' => 'y'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['secret']);         // `reveal` present -> min 8 applies

    $this->postJson('/profile', ['secret' => 'abc'])
        ->assertOk();                                     // `reveal` absent -> the check passes
});

it('applies when-conditional rules with a condition reference', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->postJson('/profile', ['admin' => 1, 'name' => 'JOHN', 'slug' => 'ok', 'phone' => '+15551234567'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);          // admin -> required applies

    $this->postJson('/profile', ['name' => 'JOHN', 'slug' => 'ok', 'phone' => '+15551234567'])
        ->assertOk();                                     // not admin -> nullable applies
});

it('keeps native lazy semantics for a condition reference returning a closure', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->postJson('/profile', ['tier' => 'pro', 'coupon' => 'basic', 'name' => 'JOHN', 'slug' => 'ok', 'phone' => '+15551234567'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['coupon']);         // the closure saw Fluent($data) with tier=pro

    $this->postJson('/profile', ['tier' => 'free', 'coupon' => 'basic', 'name' => 'JOHN', 'slug' => 'ok', 'phone' => '+15551234567'])
        ->assertOk();                                     // the conditional rules never applied
});

it('applies unless-conditional rules when the condition is falsy', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->postJson('/profile', ['discount' => 'SAVE10', 'name' => 'JOHN', 'slug' => 'ok', 'phone' => '+15551234567'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['discount']);       // not admin -> prohibited applies

    $this->postJson('/profile', ['admin' => 1, 'discount' => 'SAVE10', 'name' => 'JOHN', 'slug' => 'ok', 'phone' => '+15551234567'])
        ->assertOk();
});

it('accepts a conditional map as the whole field value', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->postJson('/profile', ['notes' => 'anything', 'name' => 'JOHN', 'slug' => 'ok', 'phone' => '+15551234567'])
        ->assertOk();                                     // condition false -> defaultRules [] -> no rules
});

it('maps nested rule references inside conditional rules', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->postJson('/profile', ['admin' => 1, 'handle' => 'BAD HANDLE', 'name' => 'JOHN', 'slug' => 'ok', 'phone' => '+15551234567'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['handle']);         // App\Requests\Rules\Slug container-made inside when.rules
});

it('throws when a rule entry declares both when and unless', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        requests:
          - name: both
            rules:
              email:
                - when:
                    condition: true
                    rules: [required]
                  unless:
                    condition: true
                    rules: [required]
        routes:
          addRoute:
            - uri: both
              methods: POST
              action: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\RequestController, store]
              metadata:
                request: both
        YAML)]);

    $this->withoutExceptionHandling();

    expect(fn (): TestResponse => $this->postJson('/both'))
        ->toThrow(LogicException::class, 'A rule entry declares both `when` and `unless`; declare one.');
});

it('throws when a conditional rule declares no condition', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        requests:
          - name: no-condition
            rules:
              email:
                - when:
                    rules: [required]
        routes:
          addRoute:
            - uri: no-condition
              methods: POST
              action: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\RequestController, store]
              metadata:
                request: no-condition
        YAML)]);

    $this->withoutExceptionHandling();

    expect(fn (): TestResponse => $this->postJson('/no-condition'))
        ->toThrow(LogicException::class, 'The `when` rule entry declares no `condition`.');
});

it('applies nothing without a validator block', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/requests.yml']);

    expect(app(Manifest::class)->validator)->toBeNull()
        ->and(app(ValidationFactory::class)->make(['name' => 'x'], ['name' => 'required'])->passes())->toBeTrue();
});

it('ignores unknown validator keys', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'manifest-').'.yml';
    file_put_contents($file, <<<'YAML'
        validator:
          bogus:
            rule: App\Never\Registered
        YAML);

    expect($this->withConfig(['laravel-declaration.manifest' => $file]))->not->toBeNull();
});

it('hydrates validator configuration properties', function (): void {
    $validator = Validator::from([
        'extend' => ['uppercase' => 'App\Validators\Uppercase@check'],
        'extendImplicit' => ['phone' => ['extension' => 'App\Validators\Phone']],
        'extendDependent' => ['guardedMin' => ['extension' => 'App\Validators\GuardedMin@check', 'message' => 'Too short.']],
        'replacer' => ['uppercase' => 'App\Validators\Uppercase@replace'],
    ]);

    expect($validator->extend)->toBe(['uppercase' => 'App\Validators\Uppercase@check'])
        ->and($validator->extendImplicit)->toBe(['phone' => ['extension' => 'App\Validators\Phone']])
        ->and($validator->extendDependent)->toBe(['guardedMin' => ['extension' => 'App\Validators\GuardedMin@check', 'message' => 'Too short.']])
        ->and($validator->replacer)->toBe(['uppercase' => 'App\Validators\Uppercase@replace']);
});
```

`Rule` is imported only if the file asserts a `ConditionalRules` unit shape directly; drop the import if unused after implementation. (`Illuminate\Support\Fluent` and `Illuminate\Validation\Rule` remain available to fixture classes.)

### 3.10 `tests/Feature/ValidateCommandTest.php` — one addition

```php
test('laravel-declaration:validate accepts a validator manifest', function (): void {
    $this->artisan('laravel-declaration:validate', ['--manifest' => __DIR__.'/../Fixtures/manifest/validator.yml'])
        ->assertSuccessful();
});
```

### 3.11 Coverage matrix (the 100% gate)

| Source line/branch | Covered by |
|---|---|
| `ValidatorDeclarationServiceProvider::boot()` guard early-return | "applies nothing without a validator block" |
| dynamic `$Factory->{$method}(...)` loop, string form of `arguments()` | `uppercase` (string), replacer `uppercase` |
| `arguments()` map form, `message` present | `slug` |
| `arguments()` map form, `message` absent | `phone` (`{extension: …}`) |
| `replacer` registry entry (no third argument) | `uppercase` replacer test |
| `rules()` list branch | existing `DeclaredRequestTest` cases |
| `rules()` non-list branch (whole-field conditional) | "accepts a conditional map as the whole field value" |
| `rule()` conditional branch, string untouched branch, class-ref branches | new + existing tests |
| `conditionalRule()` both-guard / condition-missing guard | the two `LogicException` tests |
| `conditionalRule()` `when` / `unless` picks | the when / unless tests |
| `conditionalRules()` string branch / array branch | `coupon` (pipe) / `email` (list) |
| `Validator::from()` hydration | "hydrates validator configuration properties" |

Run: `composer test`, then `composer check` (lint + rector-lint + phpstan + 100% coverage + bc-check). The two new `LogicException` guards and the provider are additive — `composer bc-check` passes (new classes, one nullable `Manifest` property).

---

## 4. Documentation updates (same change set)

1. **[declarative-tier1-gap-inventory.md](declarative-tier1-gap-inventory.md)**
   - §1 table row 6: `[/] (narrower)` → `[x]` — "**All flagged gaps closed**: `shouldFailOnUnknownFields` native-named, rule class-strings container-resolved, the `validator:` factory extensions shipped ([declarative-validator.md](declarative-validator.md)), conditional rules mapped onto `Rule::when()`/`Rule::unless()`".
   - §2.4: append a **Resolution** line: "Resolution: [declarative-validator.md](declarative-validator.md) — the `validator:` block maps the four `Factory` registry methods with references passed through untouched (Laravel's own `callClassBasedExtension`/`callClassBasedReplacer` dispatch), and conditional rules are entries keyed with the native `Rule::when()`/`Rule::unless()` method names resolved inside `DeclaredRequest::rule()`. This §2.4 closes and §1 row 6 reclassifies `[/]` → `[x]`."
   - §4 item 5: strike through, marked **done**.
   - §5 row 5 of the correction list: note the closure.
2. **[declarative-framework-api-mapping.md](declarative-framework-api-mapping.md)** — Domain 5: **Form Request Declaration** `[/]` → `[x]` (drop the stale "invented property `failOnUnknownFields` / lacks dynamic validation rule factory dispatch" clause); **Validation Factory & Custom Rules** `[ ]` → `[x]` — `validator:`.
3. **[declarative-request-to-view-roadmap.md](declarative-request-to-view-roadmap.md)** — stage table: append row `| 24 | Validation Factory Extensions | `Illuminate\Validation\Factory` / `Rule::when()` | `validator` / `requests.rules.when` | Completed |`.
4. **README.md** — add a `validator:` snippet beside the `requests:` example and one `when:` entry inside it.
5. **declarative-requests.md** — §2.4 map gains a `rules.<field>[].when/unless` row pointing at [declarative-validator.md](declarative-validator.md) §2.6.

---

## 5. Verification matrix (claim → evidence)

| Claim | Source of truth | Verified |
|---|---|---|
| `extend`/`extendImplicit`/`extendDependent`/`replacer` declared untyped with docblock types `(string, Closure\|string, ?string = null)` / `(string, Closure\|string)`; no native return types | `Factory.php:195,212,229,245` | read |
| `$message` → `$fallbackMessages[Str::snake($rule)]`; no other factory-wide setter | `Factory.php:199-201` | read |
| Contract has `extend`/`extendImplicit`/`replacer`, not `extendDependent` | `Contracts/Validation/Factory.php:26,36,45` | read |
| Extension invocation: four positional args; string → `parseCallback($callback, 'validate')` → `make()` | `Validator.php:733,1706-1730,1784-1795` | read + empirical run |
| Replacer invocation: five positional args; string → `parseCallback($callback, 'replace')` → `make()` | `FormatsMessages.php:249-267,563-591` | read + empirical run |
| Factory-wide `$message` renders when no lang line matches; otherwise the raw key renders | `Validator.php` message chain | empirical run (`The slug must be a slug.`, `validation.phone`) |
| `Str::parseCallback` default methods `validate`/`replace` | `Validator.php:1727`, `FormatsMessages.php:588` | read |
| Implicit extensions fire on absent fields; dependent ones do not | `Validator.php:824-872`, `addImplicitExtensions` 1409 | empirical run (both directions) |
| Rule names studly-normalized at parse; registry keys snaked | `ValidationRuleParser.php:280,302`, `Validator.php:1392-1449` | read + empirical run |
| `Rule::unless` swaps rules/defaultRules internally | `Rule.php:67-69` | read + empirical run |
| `ConditionalRules` resolve at `Validator::addRules` against `$this->data`; callable conditions receive `Fluent($data)` | `Validator.php:1315`, `ValidationRuleParser.php:350-372`, `ConditionalRules.php:50-55` | read + empirical run (bool, pipe-string, defaultRules, callable, whole-field) |
| `callAfterResolving` applies lazily (fires at once when already resolved); `ValidationServiceProvider` is deferrable | `Support/ServiceProvider.php:310`, `ValidationServiceProvider.php:4,29-42`, `ViewDeclarationServiceProvider.php:16` precedent | read |

### Sources

- `vendor/laravel/framework/src/Illuminate/Validation/Factory.php` (`extend` 195, `extendImplicit` 212, `extendDependent` 229, `replacer` 245, `addExtensions` 171, fallback write 199-201, `setFallbackMessages` call 184)
- `vendor/laravel/framework/src/Illuminate/Validation/Validator.php` (`addExtensions` 1392, `addImplicitExtensions` 1409, `addDependentExtensions` 1424, `addReplacers` 1479, `validateAttribute` 686/733, `dependsOnOtherFields` 744, `isValidatable` 824, `presentOrRuleIsImplicit` 844, `isImplicit` 860, `callExtension` 1706, `callClassBasedExtension` 1724, `__call` 1784)
- `vendor/laravel/framework/src/Illuminate/Validation/Concerns/FormatsMessages.php` (`makeReplacements` 249, replacers-before-builtin 260, `callReplacer` 563, `callClassBasedReplacer` 585)
- `vendor/laravel/framework/src/Illuminate/Validation/Rule.php` (`when` 54, `unless` 67), `ConditionalRules.php` (`__construct` 37, `passes` 50, `rules` 63, `defaultRules` 76)
- `vendor/laravel/framework/src/Illuminate/Validation/ValidationRuleParser.php` (`parseArrayRule` 280, `parseStringRule` 302, `filterConditionalRules` 350), `Validator.php:1315` (call site)
- `vendor/laravel/framework/src/Illuminate/Validation/ValidationServiceProvider.php` (`registerValidationFactory` 29-42, concrete class 32), `Contracts/Validation/Factory.php` (26/36/45), `Foundation/Application.php:1684` (both factory interfaces aliased to `'validator'`), `Support/ServiceProvider.php:310` (`callAfterResolving`), `Foundation/Http/FormRequest.php` (`getValidatorInstance` 93, unknown-fields hook 121-124, `createDefaultValidator` 171, `validationRules` 205, `validateNoUnknownFields` 231)
- Empirical harnesses: extension/replacer/fallback-message/implicit/dependent run and the `ConditionalRules` run (executed against the vendor autoload and re-verified with fresh harnesses during this validation pass; expectations mirrored in §3.9)
