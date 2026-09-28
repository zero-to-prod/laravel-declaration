# Declarative Requests — `Illuminate\Foundation\Http\FormRequest` API & Manifest Schema

Source of truth: `vendor/laravel/framework/src/Illuminate/Foundation/Http/FormRequest.php` (`laravel/framework` v13.33.0), with `Illuminate/Validation/ValidatesWhenResolvedTrait.php`, `Illuminate/Foundation/Providers/FormRequestServiceProvider.php`, `Illuminate/Container/BoundMethod.php`, `Illuminate/Validation/ValidationRuleParser.php`, `Illuminate/Validation/Validator.php` and `Illuminate/Routing/Route.php`.

Goal: a `requests:` block in `tests/Fixtures/manifest/requests.yml` whose **keys map 1:1 onto `FormRequest` method and property names** and whose **values are what those members return — or a PHP reference (class, method or function) that returns it** (§2.2). A request is declared entirely in YAML, once. A route references it by `name` through Laravel's own route metadata (`metadata: {request: <name>}`), so the route schema gains no key. The package ships one `FormRequest` subclass, `DeclaredRequest`, whose every member reads its key (§2.5). Dispatch stays 100% native Laravel (§1.1).

---

## 1. Public API of `FormRequest::class`

### 1.1 Lifecycle (no constructor)

A form request is never constructed manually. Resolving it from the container runs a fixed, three-step pipeline:

```php
$container->make(StoreUserRequest::class);

// 1. instantiated — FormRequest declares no constructor; nothing to wire
// 2. resolving(FormRequest::class) callback — FormRequestServiceProvider::boot()
$request = FormRequest::createFrom($app['request'], $request);   // hydrates IN PLACE:
                                                                 // query, request, attributes, cookies,
                                                                 // files, server, content, headers, locale,
                                                                 // json, session, userResolver, routeResolver
$request->setContainer($app)->setRedirector($app->make(Redirector::class));

// 3. afterResolving(ValidatesWhenResolved::class) callback
$resolved->validateResolved();   // ValidatesWhenResolvedTrait:
                                 // prepareForValidation -> authorize -> build validator -> failedValidation/passedValidation
```

`Container::getCallbacksForType()` matches `$object instanceof $type`, so callbacks registered on `FormRequest::class` and `ValidatesWhenResolved::class` fire for **every subclass**. `fireResolvingCallbacks()` runs every `resolving` callback before any `afterResolving` callback. So every hook in step 3 already sees the hydrated request and its route. Nothing is cached: every `make()` resolves fresh and re-validates.

**Dispatch path.** `Route::run()` → `runController()` → `ControllerDispatcher` (trait `ResolvesRouteDependencies`) → `transformDependency()`. A class-typed parameter that is not already present in the route parameters, and has no default value, is resolved via `$this->container->make($className)`, which runs the pipeline above. Closures take the same path (`runCallable()` → `CallableDispatcher`). Route parameters, and the matched `Route` itself, stay reachable inside the request via `$request->route()`, because `createFrom()` copies the route resolver.

### 1.2 Properties

`FormRequest` declares **no public properties**. Behavior properties are protected and set through the class attributes of §1.4, or by overriding.

| Property | Type | Default | Set by |
|---|---|---|---|
| `$container` | `Container` | — | `setContainer()` / resolving callback |
| `$redirector` | `Redirector` | — | `setRedirector()` / resolving callback |
| `$redirect` | `string\|null` | `null` | `#[RedirectTo]` |
| `$redirectRoute` | `string\|null` | `null` | `#[RedirectToRoute]` |
| `$redirectAction` | `string\|null` | `null` | override only (no attribute in v13) |
| `$errorBag` | `string` | `'default'` | `#[ErrorBag]` |
| `$stopOnFirstFailure` | `bool` | `false` | `#[StopOnFirstFailure]` |
| `$validator` | `Validator\|null` | `null` | `setValidator()` |
| static `$globalFailOnUnknownFields` | `bool` | `false` | `FormRequest::failOnUnknownFields(bool $value = true)` |
| `$json`, `$convertedFiles`, `$userResolver`, `$routeResolver`, `$cachedAcceptHeader` | — | — | inherited from `Request` (runtime state) |

### 1.3 Public methods

**Declaration targets (overridable).** These are what a request *says*; `validateResolved()` (§1.1) consumes them. Five are **opt-in by `method_exists()`**: declaring the method changes the pipeline even when it returns a default (§2.5 relies on this).

| Method | Signature | Consumed by |
|---|---|---|
| `authorize()` | `(): bool\|Response` | `passesAuthorization()` — opt-in; `$this->container->call([$this, 'authorize'])`; an `Illuminate\Auth\Access\Response` is `->authorize()`d (throws when denied); `false` → `failedAuthorization()`; absent → `true` |
| `rules()` | `(): array` | `validationRules()` — opt-in; `$this->container->call([$this, 'rules'])`, so it may type-hint dependencies; absent → `[]` |
| `messages()` | `(): array` | arg 3 of `ValidationFactory::make()`; default `[]` |
| `attributes()` | `(): array` | arg 4 of `ValidationFactory::make()`; default `[]` |
| `validationData()` | `(): array` | the data validated; default `$this->all()` |
| `prepareForValidation()` | `(): void` | step 1 of `validateResolved()` (merge/normalize input); default no-op |
| `passedValidation()` | `(): void` | last step of `validateResolved()`; default no-op |
| `withValidator($validator)` | `(Validator $validator): void` | opt-in; called directly after the validator is built, before it runs |
| `after()` | `(): array<callable>` | opt-in; `$validator->after($this->container->call($this->after(...), ['validator' => $validator]))`. Each entry (Closure or invokable instance) is invoked with the `Validator` |
| `validator()` | `(ValidationFactory $factory): Validator` | opt-in; **replaces** `createDefaultValidator()`: `$this->container->call($this->validator(...), ['factory' => $factory])` |

**Error hooks (overridable)**

| Method | Signature | Effect |
|---|---|---|
| `failedValidation($validator)` | `(Validator $validator)`, throws by default | `throw (new $exception($validator))->errorBag($this->errorBag)->redirectTo($this->getRedirectUrl())`. Exception class from `$validator->getException()` (default `ValidationException`). An override that returns lets `validateResolved()` continue to `passedValidation()` |
| `failedAuthorization()` | `()`, throws by default | throws `Illuminate\Auth\Access\AuthorizationException` (overrides the trait's `UnauthorizedException`) |
| `getRedirectUrl()` | `(): string` | `$redirect` → `$redirectRoute` → `$redirectAction` → `$url->previous()` |
| `shouldFailOnUnknownFields()` | `(): bool` | `#[FailOnUnknownFields]` value, else static `$globalFailOnUnknownFields` |

**Runtime accessors (resolved data — not declaration targets)**

| Method | Signature | Returns |
|---|---|---|
| `validated($key = null, $default = null)` | `($key = null, $default = null): mixed` | `data_get` of validated input |
| `safe(?$keys = null)` | `(?array $keys = null): ValidatedInput\|array` | validated-input container (`.only()` / `.except()`) |
| `setValidator(Validator $validator)` | `(Validator $validator): $this` | sets the validator |
| `setContainer(Container $container)` / `setRedirector(Redirector $redirector)` | `($x): $this` | wired by the resolving callback |
| `failOnUnknownFields(bool $value = true)` (static) / `flushState()` (static) | `(bool $value = true): void` / `(): void` | global unknown-field rejection / reset |

### 1.4 Class attributes — the declarative property layer

Target: `TARGET_CLASS`. `configureFromAttributes()` applies `StopOnFirstFailure`, `RedirectTo`, `RedirectToRoute` and `ErrorBag`. `getValidatorInstance()` calls it before it builds the validator. It looks them up through `nearestClassWithAttribute([...])`, which walks up the inheritance chain; a class that declares the property itself wins over an inherited attribute. `FailOnUnknownFields` is read separately by `shouldFailOnUnknownFields()`.

| Attribute | Constructor | Sets / Effect |
|---|---|---|
| `StopOnFirstFailure` | — | `$stopOnFirstFailure = true` → `Validator::stopOnFirstFailure(true)` |
| `FailOnUnknownFields` | `(bool $value = true)` | read by `shouldFailOnUnknownFields()`: appends `validateNoUnknownFields()` as an `after` callback. It rejects body keys (JSON, or form `$this->request`; not the query string) not covered by `rules()`. `*_confirmation` siblings and `*` wildcard rules are exempt |
| `RedirectTo` | `(string $url)` | `$redirect`: failed validation redirects to the URL |
| `RedirectToRoute` | `(string $route)` | `$redirectRoute`: failed validation redirects to the named route |
| `ErrorBag` | `(string $name)` | `$errorBag`: errors flash into the named bag |

### 1.5 Inherited request surface (`Illuminate\Http\Request`)

`FormRequest extends Request extends Symfony\Component\HttpFoundation\Request`, so the full request API is available to every hook. Groups (see `docs/repos/laravel/docs/requests.md` for the complete catalog):

| Group | Methods |
|---|---|
| Input | `all`, `input`, `query`, `post`, `string`, `boolean`, `integer`, `float`, `date`, `enum`, `enums`, `collect`, `only`, `except`, `merge`, `mergeIfMissing`, `replace`, `json` |
| Presence | `has`, `whenHas`, `filled`, `whenFilled`, `isNotFilled`, `anyFilled`, `missing`, `whenMissing`, `keys` |
| Files | `file`, `allFiles`, `hasFile` |
| Cookies / headers | `cookie`, `hasCookie`, `header`, `hasHeader`, `bearerToken`, `server` |
| Identity | `user($guard = null)`, `route($param = null, $default = null)`: the two `authorize` building blocks |
| Session / flash | `session()`, `old()`, `flash`, `flashOnly`, `flashExcept` |
| URL / method | `method`, `path`, `decodedPath`, `url`, `fullUrl`, `host`, `ip`, `segments`, `is`, `routeIs` |
| Magic `validate` | `$request->validate($rules, ...$params)` is a macro registered by `FoundationServiceProvider::registerRequestValidation()` → `validator($this->all(), $rules, ...)->validate()`. **Not used by the manifest path** |

Symfony's `Request::$request` is the POST-body `ParameterBag`. A subclass must not reuse the name `$request` for its own state.

### 1.6 Exceptions

| Exception | Thrown by | Rendered as |
|---|---|---|
| `Illuminate\Validation\ValidationException` | `failedValidation()` | `422` + JSON errors for XHR/`expectsJson`; otherwise redirect to `getRedirectUrl()` with errors flashed into `errorBag` |
| `Illuminate\Auth\Access\AuthorizationException` | `failedAuthorization()` | `403` |

---

## 2. Manifest schema proposal (`app.yml`)

### 2.1 Design rule

> **Every key in a request object is a `FormRequest` method or property name. Every value is what that method returns (or the property holds), or a PHP reference (§2.2) that Laravel's container calls in its place.** One reserved key, `name`, is the handle. A route references it through `metadata: {request: <name>}`, which is Laravel's `Route::metadata()`, already a route key (declarative-routing.md §2.4). `DeclaredRequest` implements each member as a one-line read of its key (§2.5).

This keeps the implementation to one class with one line per key, and it adds no route key. It also guarantees the YAML can never drift from the underlying API: a key *is* the member it replaces.

### 2.2 PHP references

YAML has no `::class` constant, Closures or `new`. PHP is written as a **reference string**, and `DeclaredRequest` resolves every reference through `Container::call()`, the same call Laravel makes for `rules()`, `authorize()`, `after()` and `validator()` (§1.3). Resolution is `Illuminate\Container\BoundMethod::call()`, verbatim:

| YAML form | Resolved as |
|---|---|
| `App\Http\Gates\CreateUser` | invokable class: `make()` + `__invoke()` |
| `App\Http\Hooks\TitleCaseName@handle` | `make()` + instance method |
| `App\Http\Gates\Avatars::update` | static method |
| `App\Http\Hooks\normalize_avatar` | namespaced function. PSR-4 autoloads classes only, so load the file through Composer `autoload.files` |

There is no array form. `Container::call()` would treat `[Class, method]` as a PHP array callable, which calls the method statically. A non-static method then throws `Error`, unlike a route `action`. `Class::method` already covers static methods, so a reference is always one string.

Write a reference plain or single-quoted. Double quotes make `\` an escape: `"App\Http\Gates\CreateUser"` throws `Symfony\Component\Yaml\Exception\ParseException`.

**Parameters.** Every call passes `['request' => $this]`, plus Laravel's own named argument where Laravel passes one: `validator` for `withValidator`, `after`, `failedValidation`, and `factory` for `validator`. Everything else is container-injected, exactly as in `rules()`. `BoundMethod::addDependencyForCallParameter()` matches by **name** first, so name the parameters `$request`, `$validator` and `$factory`:

- `DeclaredRequest $request` or `Illuminate\Http\Request $request` receives the declared request.
- `DeclaredRequest $req` is `make()`d. That re-enters the pipeline (§1.1), which calls the same hook again. It recurses until PHP crashes.
- `Illuminate\Http\Request $req` receives the base request. `createFrom()` hands it the same JSON bag (`setJson($from->json())`), so it sees `prepareForValidation` merges on a JSON request, and not on a form request.
- `Validator $v` is `make()`d from an unbound contract and throws `BindingResolutionException`.

**Value or reference, per key.** A string is a reference. Anything else (a map, a list or a `bool`) is a value. `after` is always a list of references.

**Value or reference, per rule** (an entry in a field's rule list):

- A string whose **rule name**, the text before the first `:`, contains `\` is a reference. Everything else passes to Laravel untouched. `ValidationRuleParser::parseStringRule()` splits on that first `:`, and Laravel rule names never contain `\`: `'App\Rules\Uppercase'` as a rule string throws `BadMethodCallException: Method Illuminate\Validation\Validator::validateApp\Rules\Uppercase does not exist.` Parameters may contain `\` (`exists:App\Models\Role,name`, `regex:/^\d+$/`), but they always follow the `:`.
- A **class** reference is `make()`d, and the instance *is* the rule (≙ `new Uppercase`). It must implement `ValidationRule`, `Rule` or `InvokableRule`. Every other form (`Class@method`, `Class::method`, function) is **called**, and its return value is the rule (≙ `new UniqueTenantEmail($this->user()->tenant_id)`). PHP class names are case-insensitive, so a function whose name differs from a class name only by case (`App\Rules\slug` beside `App\Rules\Slug`) can be `make()`d as the class.
- Functions in a rule list are recognized only by their namespace. Bare names collide: `date` and `file` are both PHP functions and Laravel rules.
- An array entry is Laravel's array-form rule (`['size', 10]`, `ValidationRuleParser::parseArrayRule()`), passed untouched.

A field value that is a string (`nullable|string|max:32`) is Laravel's pipe syntax, passed untouched. References go in list form.

### 2.3 Full example

```yaml
requests:
  - name: user                                   # reserved: the handle routes reference
    authorize: App\Http\Gates\CreateUser          # invokable -> bool|Response
    rules:
      name: [required, string, max:255]           # Laravel rules, untouched
      nickname: nullable|string|max:32            # pipe string, untouched
      role: [required, 'exists:App\Models\Role,name']   # `\` after the `:` -> Laravel param; quoted: the comma would split the flow list
      slug: [required, App\Rules\Slug]            # rule class -> make() (≙ new Slug)
      email:
        - required
        - email
        - App\Rules\UniqueTenantEmail::forRequest # the special rule, resolved per request by a static factory
      backup_email:
        - nullable
        - email
        - App\Rules\unique_tenant_email           # the same rule, resolved by a function
    messages:                                     # -> messages()
      name.required: A name is required.
    attributes:                                   # -> attributes()
      email: email address
    prepareForValidation: App\Http\Hooks\TitleCaseName@handle
    after:
      - App\Validation\ValidateUserStatus         # invokable; receives $validator
    redirectRoute: users.index                    # ≙ #[RedirectToRoute('users.index')]
    errorBag: user                                # ≙ #[ErrorBag('user')]
    stopOnFirstFailure: true                      # ≙ #[StopOnFirstFailure]
    failOnUnknownFields: true                     # ≙ #[FailOnUnknownFields]

  - name: avatar
    authorize: App\Http\Gates\Avatars::update     # static method
    rules: App\Http\Rules\AvatarRules             # the whole rules() as one invokable
    prepareForValidation: App\Http\Hooks\normalize_avatar   # function

routes:
  - path: users
    methods: POST
    action: [App\Http\Controllers\UserController, store]
    metadata:
      request: user                               # -> Route::metadata(['request' => 'user'])

  - path: "users/{user}"
    methods: PUT
    action: [App\Http\Controllers\UserController, update]
    metadata:
      request: user                               # declared once, reused

  - path: "users/{user}/avatar"
    methods: POST
    action: [App\Http\Controllers\UserController, avatar]
    metadata:
      request: avatar

  - path: "/"                                     # no `metadata.request` -> no validation, as before
    methods: GET
    action: App\Http\Controllers\HomeController
```

The special rule. The class is the rule; the static method and the function are two ways to resolve it with request context:

```php
namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use ZeroToProd\LaravelDeclaration\DeclaredRequest;

final class UniqueTenantEmail implements ValidationRule
{
    public function __construct(private int $tenantId) {}

    // rules.email[]: App\Rules\UniqueTenantEmail::forRequest
    public static function forRequest(DeclaredRequest $request): self
    {
        return new self($request->user()->tenant_id);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (User::query()->where('tenant_id', $this->tenantId)->where('email', $value)->exists()) {
            $fail('The :attribute has already been taken.');
        }
    }
}

// rules.backup_email[]: App\Rules\unique_tenant_email — loaded through composer `autoload.files`
function unique_tenant_email(DeclaredRequest $request): UniqueTenantEmail
{
    return new UniqueTenantEmail($request->user()->tenant_id);
}
```

The other targets, one line each. Each is an ordinary class or function; nothing extends a package type:

```php
final class CreateUser         { public function __invoke(DeclaredRequest $request): bool { return $request->user()->can('create', User::class); } }
final class Avatars            { public static function update(DeclaredRequest $request): bool { return $request->user()->is($request->route('user')); } }
final class AvatarRules        { public function __invoke(DeclaredRequest $request): array { return ['avatar' => ['required', 'image', 'max:2048']]; } }
final class TitleCaseName      { public function handle(DeclaredRequest $request): void { $request->merge(['name' => Str::title($request->input('name'))]); } }
final class ValidateUserStatus { public function __invoke(Validator $validator): void { /* $validator->errors()->add(...) */ } }
final class Slug implements ValidationRule { public function validate(string $attribute, mixed $value, Closure $fail): void { /* ... */ } }
function normalize_avatar(DeclaredRequest $request): void { /* $request->merge(...) */ }
```

The controller. The type-hint is Laravel's own validation trigger (§1.1), and the runtime accessors are unchanged:

```php
use ZeroToProd\LaravelDeclaration\DeclaredRequest;

public function store(DeclaredRequest $request): RedirectResponse
{
    $validated = $request->validated();
    $partial = $request->safe()->only(['name', 'email']);

    // Store the user...

    return redirect()->route('users.index');
}
```

### 2.4 Key → member → signature map

| YAML key | `FormRequest` member | Value shape (YAML) | Reference receives | Absent → |
|---|---|---|---|---|
| `name` | — (reserved) | `string`, unique | — | required |
| `authorize` | `authorize(): bool\|Response` | `bool` \| reference | `$request` | `true` (≙ no `authorize()`) |
| `rules` | `rules(): array` | `map<field, string \| list<rule>>` \| reference | `$request` | `[]` |
| `rules.<field>[]` | one rule | Laravel rule (`string`, `[name, ...params]`) \| reference (§2.2) | `$request` | — |
| `messages` | `messages(): array` | `map<'field.rule', string>` \| reference | `$request` | `[]` |
| `attributes` | `attributes(): array` | `map<field, label>` \| reference | `$request` | `[]` |
| `validationData` | `validationData(): array` | reference | `$request` | `$this->all()` |
| `prepareForValidation` | `prepareForValidation(): void` | reference | `$request` | no-op |
| `passedValidation` | `passedValidation(): void` | reference | `$request` | no-op |
| `withValidator` | `withValidator(Validator): void` | reference | `$request`, `$validator` | no-op |
| `after` | `after(): array` | `list<reference>` | `$request`, `$validator` | `[]` |
| `validator` | `validator(ValidationFactory): Validator` | reference; replaces `rules`/`messages`/`attributes` | `$request`, `$factory` | `createDefaultValidator()` |
| `failedValidation` | `failedValidation(Validator)` | reference; runs before Laravel's throw (§2.6) | `$request`, `$validator` | `ValidationException` |
| `failedAuthorization` | `failedAuthorization()` | reference; runs before Laravel's throw (§2.6) | `$request` | `AuthorizationException` |
| `redirect` | `$redirect` ≙ `#[RedirectTo]` | `string` (URL) | — | `null` |
| `redirectRoute` | `$redirectRoute` ≙ `#[RedirectToRoute]` | `string` (route name) | — | `null` |
| `redirectAction` | `$redirectAction` (no attribute) | `string` (`Class@method`) | — | `null` |
| `errorBag` | `$errorBag` ≙ `#[ErrorBag]` | `string` | — | `'default'` |
| `stopOnFirstFailure` | `$stopOnFirstFailure` ≙ `#[StopOnFirstFailure]` | `bool` | — | `false` |
| `failOnUnknownFields` | `shouldFailOnUnknownFields()` ≙ `#[FailOnUnknownFields]` | `bool` | — | global `FormRequest::failOnUnknownFields()` |
| `metadata.request` (on a route) | `Route::metadata(['request' => $name])` | request `name` | — | no declaration (§2.5) |

Unknown keys are ignored, as they are for routes: `DataModel` drops any key it has no property for (`Request::from([... 'bogus' => 1])` hydrates without it). Uniqueness of `name` is not enforced: `key_by` keeps the last duplicate, silently.

### 2.5 Registration algorithm (for the provider)

One `DataModel` addition. `Manifest::$requests` is `Collection<string, Request>`, with the same `mapOf` + `key_by` cast as `Manifest::$providers`. An absent `requests:` block hydrates to an empty collection. `Request` is a `DataModel` sibling of `src/Route.php`: one property per §2.4 row, defaulting to the "Absent →" column.

The provider binds the manifest, or an empty one when the file is missing, so the request can read it. This is one line in `register()`, after `resolveManifest()` (which returns an empty manifest for a missing file):

```php
$this->app->instance(Manifest::class, $Manifest);
```

`DeclaredRequest` is the single seam. Each member reads its key from the declaration that its route names:

```php
namespace ZeroToProd\LaravelDeclaration;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use LogicException;

class DeclaredRequest extends FormRequest
{
    public function authorize(): bool|Response { return $this->resolve($this->declaration()->authorize ?? true); }
    public function messages(): array { return $this->resolve($this->declaration()->messages); }
    public function attributes(): array { return $this->resolve($this->declaration()->attributes); }
    public function validationData(): array { return $this->resolve($this->declaration()->validationData) ?? parent::validationData(); }
    protected function prepareForValidation(): void { $this->resolve($this->declaration()->prepareForValidation); }
    protected function passedValidation(): void { $this->resolve($this->declaration()->passedValidation); }
    public function withValidator(Validator $validator): void { $this->resolve($this->declaration()->withValidator, ['validator' => $validator]); }
    protected function shouldFailOnUnknownFields(): bool { return $this->declaration()->failOnUnknownFields ?? parent::shouldFailOnUnknownFields(); }

    public function rules(): array
    {
        return array_map(
            fn (mixed $rules): mixed => is_array($rules) ? array_map($this->rule(...), $rules) : $rules,
            $this->resolve($this->declaration()->rules),
        );
    }

    /** @return list<Closure> */
    public function after(?Validator $validator = null): array
    {
        return array_map(
            fn (string $hook): Closure => fn (Validator $validator): mixed => $this->resolve($hook, ['validator' => $validator]),
            $this->declaration()->after,
        );
    }

    // declaring validator() opts in (§1.3), so an undeclared `validator` falls back to Laravel's default
    public function validator(ValidationFactory $factory): Validator
    {
        return $this->resolve($this->declaration()->validator, ['factory' => $factory]) ?? $this->createDefaultValidator($factory);
    }

    protected function failedValidation(Validator $validator): void
    {
        $this->resolve($this->declaration()->failedValidation, ['validator' => $validator]);

        parent::failedValidation($validator);   // throws: a hook that returns still fails the request
    }

    protected function failedAuthorization(): never
    {
        $this->resolve($this->declaration()->failedAuthorization);

        throw new AuthorizationException;       // ≙ parent::failedAuthorization()
    }

    // property keys: set where Laravel applies #[RedirectTo], #[RedirectToRoute], #[ErrorBag] and #[StopOnFirstFailure] (§1.4)
    protected function configureFromAttributes(): void
    {
        $declaration = $this->declaration();

        $this->redirect = $declaration->redirect;
        $this->redirectRoute = $declaration->redirectRoute;
        $this->redirectAction = $declaration->redirectAction;
        $this->errorBag = $declaration->errorBag;
        $this->stopOnFirstFailure = $declaration->stopOnFirstFailure;
    }

    private function declaration(): Request
    {
        $name = $this->route()->getMetadata('request');

        return $this->container->make(Manifest::class)->requests->get($name)
            ?? throw new LogicException("The route declares no `metadata.request`, or [{$name}] is not declared under `requests`.");
    }

    /** A string is a reference (§2.2), called the way Laravel calls rules(); anything else is the value. */
    private function resolve(mixed $value, array $parameters = []): mixed
    {
        return is_string($value) ? $this->container->call($value, ['request' => $this, ...$parameters]) : $value;
    }

    /** A rule-list entry is a reference only when its rule name (before the first `:`) is namespaced (§2.2). */
    private function rule(mixed $rule): mixed
    {
        if (! is_string($rule) || ! str_contains(Str::before($rule, ':'), '\\')) {
            return $rule;
        }

        return class_exists($rule) ? $this->container->make($rule) : $this->resolve($rule);
    }
}
```

The class holds no state and needs no `resolving` callback. Every member runs inside `validateResolved()`, after `createFrom()` has copied the route resolver (§1.1), so `$this->route()` returns the matched route. Outside one (`app(DeclaredRequest::class)` in a job or command), `route()` returns `null`, and the first member throws `Error`, not the `LogicException`. Route registration is unchanged: `metadata` is already a route key.

The type-hint is Laravel's validation trigger, exactly as for a hand-written `FormRequest`. A route whose action hints no `DeclaredRequest` does not validate. A route whose action hints another `FormRequest` runs that class's rules. A `DeclaredRequest` on a route with no `metadata.request`, or one that names an undeclared request, throws the `LogicException` on first use. `DeclaredView` is the exception: it resolves `DeclaredRequest` itself when its route declares `metadata.request` ([declarative-view-data.md](declarative-view-data.md) §2.5).

### 2.6 Notes / non-goals

- Runtime accessors (`validated`, `safe`, `setValidator`, ...) are called by the action, not declared.
- `validator` replaces `createDefaultValidator()`, which is what applies `rules`, `messages`, `attributes`, `stopOnFirstFailure` and Precognition rule filtering. A declared `validator` owns all of them, the same as overriding `validator()` in a class. `failOnUnknownFields` is the exception: `validateNoUnknownFields()` reads `validationRules()` (`rules`), not the custom validator. A `validator` with `failOnUnknownFields: true` and no `rules` rejects every field.
- `failedValidation` and `failedAuthorization` are the one deliberate departure from a class override. The hook runs, then Laravel's default throws. So a hook that throws its own exception (for example `HttpResponseException`) replaces the default, and a hook that returns still fails the request. A class override that returns would let the request through (§1.3).
- References run on every resolve, and nothing is cached (§1.1). With `failOnUnknownFields`, `rules` runs twice, because `validateNoUnknownFields()` re-reads `validationRules()` (Laravel's behavior for `rules()` too).
- `metadata.request` is an ordinary metadata entry. `Route::metadata()` merges it with the route's other metadata. The route stores only the name string, so it is `route:cache`-safe.
- A hand-written `FormRequest` subclass needs no manifest. Type-hint it, as in plain Laravel.
