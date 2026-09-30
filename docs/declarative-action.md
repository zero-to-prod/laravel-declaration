# Declarative Action & Redirects — `Illuminate\Routing\Controller` State Mutations, Dynamic Dispatch & Redirect Responses

Source of truth: `vendor/laravel/framework/src/Illuminate/Routing/Controller.php` (`laravel/framework` v13.33.0), with:
- `Illuminate\Routing\RedirectController.php` (`RedirectController.php:10`)
- `Illuminate\Routing\Redirector.php` (`Redirector.php:9`)
- `Illuminate\Http\RedirectResponse.php` (`RedirectResponse.php:16`)
- `Illuminate\Routing\ControllerDispatcher.php` (`ControllerDispatcher.php:9`)
- `Illuminate\Routing\RouteParameterBinder.php` (`RouteParameterBinder.php:7`)
- `Illuminate\Routing\Route.php` (`Route.php:35`)
- `Illuminate\Routing\Router.php` (`Router.php:38`)
- `Illuminate\Database\Eloquent\Model.php` (`Model.php:45`)
- `Illuminate\Database\Eloquent\Builder.php` (`Builder.php:36`)
- `Illuminate\Database\Eloquent\Concerns\HasTimestamps.php` (`HasTimestamps.php:8`)
- `Illuminate\Database\Eloquent\SoftDeletes.php` (`SoftDeletes.php:16`)

Laravel documentation references:
- `docs/repos/laravel/docs/controllers.md` (Single Action Controllers:77, Controller Middleware:116)
- `docs/repos/laravel/docs/responses.md` (Redirects:203, Redirecting to Named Routes:221, Controller Actions:261, External Domains:280, Flashed Session Data:289)
- `docs/repos/laravel/docs/routing.md` (Redirect Routes:143, Route Parameters:271, Implicit Binding:581)
- `docs/repos/laravel/docs/migrations.md` (Auto-incrementing IDs:785, Column Modifiers:627, Foreign Keys:716, Timestamps:1063, Soft Deletes:985, Cascade:1571)
- `docs/repos/laravel/docs/eloquent.md` (Inserts:757, Updates:811, Mass Assignment:972, Deleting:1087, Soft Deleting:1148, Restoring:1195, Permanent Deletion:1218)

Grounding documentation: `docs/declarative-request-to-view-roadmap.md` §1 Stage 12–13, §3 Phase 7, §4 End State.

Goal: a state-changing action route whose **`setDefaults` keys declare the Eloquent model mutation (`model`, `target`, `call`, `args`), the destination redirect (`redirect`, `route`, `to`, `back`, `away`), the HTTP response status (`status`), and session flash data (`with`, `withInput`, `withErrors`)**. This is Phase 7 of [declarative-request-to-view-roadmap.md](declarative-request-to-view-roadmap.md): Stage 12 (state mutation pipeline) and Stage 13 (redirect + session flash), joining Stage 9 (bound route parameter) and Stage 10 (`DeclaredRequest` validation) to Stage 14/15/16 (view rendering). The database table and column schemas defined in Phase 6 (`docs/declarative-schema.md`) serve as the **system of record** for persistent entity state. Grounded in the architectural priority **Laravel API mapping first, glue/composition second**, `DeclaredAction extends Controller` (§2.5) functions as a pure **Tier 2 (Declarative Seam)** that wraps writes within `Illuminate\Database\DatabaseManager::transaction()` (`db:`) and delegates redirection directly to `Illuminate\Routing\Redirector` (`redirect:`) and `Illuminate\Http\RedirectResponse` without bespoke attribute classes or invented verbs.

---

## 1. Public API of `Controller::class`, `Redirector::class`, `RedirectResponse::class`, `Model::class`

### 1.1 Lifecycle position (when the declaration runs)

```
// 1. Route Registration (manifest/app.yml -> registerRoutes())
$Router->addRoute(['POST', 'PATCH', 'DELETE'], 'todos/{todo}', DeclaredAction::class)
    ->middleware(['web', SubstituteBindings::class])
    ->metadata(['request' => 'update-todo'])
    ->setDefaults([
        'target'   => 'todo',                                // Bound route parameter name
        'call'     => 'update',                              // Model mutation method
        'redirect' => 'todos.index',                         // Target route name or path
        'with'     => ['status' => 'Todo updated!'],         // Session flash data
    ]);

// 2. Inbound HTTP Request Lifecycle
HTTP POST/PATCH/DELETE /todos/1
    │
    ▼
Router::findRoute($request)                                  // Matches Route instance
    │
    ▼
RouteParameterBinder::parameters($request)                   // Merges URL params with setDefaults (replaceDefaults())
    │
    ▼
Pipeline: Middleware
    │
    ├─► Session Middleware (`web` group)                     // Starts session store on request
    │
    ├─► SubstituteBindings Middleware                        // Invokes Router::model() / Router::bind()
    │       └─► Todo::findOrFail(1)                          // Parameter 'todo' bound to Todo instance
    │
    └─► ControllerDispatcher::dispatch($route, $controller, $method)
            │
            ├─► DeclaredAction::callAction('__invoke', $parameters)
            │       │   (preserves associative string keys: 'target', 'call', 'redirect', 'with')
            │       ▼
            ├─► Stage 10: DeclaredRequest validation
            │       app(DeclaredRequest::class)->validateResolved()
            │       On failure: throws ValidationException -> redirects back with errors (no mutation runs)
            │       On success: extracts $request->validated() attributes
            │       ▼
            ├─► Stage 12: Atomic State Mutation inside DB::transaction()
            │       Target Resolution:
            │           - If 'model' set: App\Models\Todo (class root)
            │           - If 'target' set: $parameters['todo'] (bound model instance)
            │       Native Eloquent Execution:
            │           DB::transaction(fn() => match ($call) {
            │               'create'     => $modelClass::create($attributes),
            │               'update'     => $targetInstance->update($attributes),
            │               'delete'     => $targetInstance->delete(),
            │               'restore'    => $targetInstance->restore(),
            │               'touch'      => $targetInstance->touch(),
            │               'forceDelete'=> $targetInstance->forceDelete(),
            │               default      => $target->{$call}($attributes),
            │           })
            │       ▼
            ├─► Stage 13: Redirect & Flash Resolution via Native Redirector
            │       Delegation to native Redirector:
            │           - route:      $redirector->route('todos.index', $params, $status, $headers)
            │           - to:         $redirector->to('/', $status, $headers)
            │           - back:       $redirector->back($status, $headers)
            │           - away:       $redirector->away('https://external.com', $status, $headers)
            │           - action:     $redirector->action($action, $params, $status, $headers)
            │       Flash Chaining via RedirectResponse:
            │           - with:       $response->with(['status' => 'Todo updated!'])
            │           - withInput:  $response->withInput()
            │           - withErrors: $response->withErrors($errors)
            │       ▼
            └─► Returns Illuminate\Http\RedirectResponse (HTTP 302 / 303)
```

Consequences, each verified against v13.33.0 with Testbench:

1. **`SubstituteBindings` runs before the action executes.** Bound route parameters (`{todo}`) are resolved to `Illuminate\Database\Eloquent\Model` instances via `Router::model()` or `Router::bind()` before `ControllerDispatcher::dispatch()` is reached. If the entity does not exist, Laravel aborts with `404 Not Found` via `Model::findOrFail()` prior to mutation dispatch.
2. **Validation strictly precedes mutation.** If `metadata.request` is present on the route, `app(DeclaredRequest::class)->validateResolved()` runs before any mutation method is called. Validation failure throws `Illuminate\Validation\ValidationException`, which Laravel's HTTP exception handler converts into an immediate `302 Redirect` back to the referring page with session errors and flashed input. Zero database mutations execute on invalid input.
3. **Associative string parameters preserved.** By overriding `Controller::callAction($method, $parameters)`, `DeclaredAction` preserves associative array keys rather than positional `array_values($parameters)`. Default values declared in `setDefaults` (`model`, `target`, `call`, `redirect`, `with`) arrive keyed by their declaration names in `__invoke(...$args)`.
4. **`route:cache`-safe.** All default parameters stored in `Route::$defaults` are primitive scalars, lists, and associative maps. No runtime closures are stored in the route definition, guaranteeing seamless compilation via `php artisan route:cache`.
5. **Session store binding required for flash.** Session flash operations (`with()`, `withInput()`) require an active session driver (`Illuminate\Session\Store`) provided by the `web` middleware group (`Illuminate\Session\Middleware\StartSession`).

### 1.2 Properties

**Internal state on `Illuminate\Routing\Controller`:**

| Property | Type | Visibility | Role in Declaration |
|---|---|---|---|
| `$middleware` | `array<int, array{middleware: mixed, options: array}>` | `protected` | Controller-level middleware definitions (`Controller.php:14`) |

**Internal state on `Illuminate\Routing\Redirector`:**

| Property | Type | Visibility | Role in Declaration |
|---|---|---|---|
| `$generator` | `Illuminate\Routing\UrlGenerator` | `protected` | URL generator used to resolve named routes, absolute paths, and previous locations (`Redirector.php:18`) |
| `$session` | `Illuminate\Session\Store` | `protected` | Active session store bound to the redirector for flashing session messages (`Redirector.php:25`) |

**Internal state on `Illuminate\Http\RedirectResponse`:**

| Property | Type | Visibility | Role in Declaration |
|---|---|---|---|
| `$request` | `Illuminate\Http\Request` | `protected` | Current HTTP request instance, used for flashing old input (`RedirectResponse.php:27`) |
| `$session` | `Illuminate\Session\Store` | `protected` | Session store receiving flash data via `session()->flash()` (`RedirectResponse.php:34`) |

**Route internal state:**

| Property | Type | Set by | Read by |
|---|---|---|---|
| `Route::$defaults` (public) | `array<string, mixed>` | `setDefaults()` | `RouteParameterBinder::replaceDefaults()`, `AbstractRouteCollection::compile()` |
| `Route::$action['metadata']` (public `$action`) | `array<string, mixed>` | `metadata()` | `Route::getMetadata()`: `DeclaredAction`, `DeclaredRequest` |

**Not declaration targets:**
- `$generator`, `$session`, `$request`: Framework runtime instances managed by the service container and HTTP middleware pipeline.
- Controller-level `$middleware`: Handled declaratively by the route-level `middleware` manifest key.

### 1.3 Public methods

#### 1.3.1 Declaration targets: Target and Mutation Dispatch

Together, these declare the target entity and the state-changing mutation:

| Key / Method | Target / Signature | Effect | Source |
|---|---|---|---|
| `model` | `class-string<Model>` | Target Eloquent model class for static mutations (`create`) | `Model.php:45` |
| `target` | `string` | Route parameter name holding bound model instance (`todo`) | `RouteParameterBinder.php:102` |
| `call` | `string` | Method name dynamically dispatched on target entity | `Model.php:45`, `Builder.php:36` |
| `column` | `string` | Attribute name toggled or updated during mutation | `Model.php:1402`, `HasTimestamps.php:50` |
| `args` | `array<string, mixed>\|null` | Explicit arguments passed to the mutation method | `DeclaredAction` |
| `Model::create` | `create(array $attributes = []): Model` | Static mass-assignment record creation | `Builder.php:1255` |
| `Model::update` | `update(array $attributes = [], array $options = []): bool` | Instance mass-assignment record update | `Model.php:1171` |
| `Model::delete` | `delete(): ?bool` | Instance deletion (or soft delete timestamp update) | `Model.php:1782` |
| `Model::save` | `save(array $options = []): bool` | Persists dirty instance attributes to database | `Model.php:1402` |
| `Model::touch` | `touch(?string $attribute = null): bool` | Updates model timestamp columns | `HasTimestamps.php:50` |
| `Model::restore` | `restore(): ?bool` | Restores a soft-deleted model instance | `SoftDeletes.php:165` |
| `Model::forceDelete`| `forceDelete(): ?bool` | Permanently removes a soft-deleted record | `SoftDeletes.php:51` |

#### 1.3.2 Declaration targets: Redirect and Flash Dispatch

Together, these declare the redirect destination and flashed session data:

| Key / Method | Signature | Effect | Source |
|---|---|---|---|
| `redirect` | `string` | Smart redirect: resolves to `route()` if route exists, else `to()` | `Redirector.php:111`, `151` |
| `route` | `string\|array{name: string, parameters?: array}` | Redirects to a named route via `Redirector::route()` | `Redirector.php:151` |
| `to` | `string` | Redirects to an internal path via `Redirector::to()` | `Redirector.php:111` |
| `back` | `bool\|array{fallback?: string}` | Redirects to the referring URL via `Redirector::back()` | `Redirector.php:45` |
| `away` | `string` | Redirects to external URI without validation via `Redirector::away()`| `Redirector.php:124` |
| `status` | `int` | HTTP redirect status code (default `302` Found) | `RedirectResponse.php:16` |
| `with` | `array<string, mixed>` | Flashes key-value pairs to session store via `with()` | `RedirectResponse.php:43` |
| `withInput` | `bool\|list<string>` | Flashes request input to session via `withInput()` | `RedirectResponse.php:60` |
| `withErrors` | `string\|array` | Flashes error bag to session via `withErrors()` | `RedirectResponse.php:117` |

**Not declaration targets:**

| Method | Why no key |
|---|---|
| `Controller::middleware` | Handled declaratively via the `middleware` key on the route declaration |
| `Redirector::refresh` | Equivalent to `back: true` or `redirect: current_uri` |
| `Redirector::intended` | Specific to authentication state flows (handled by dedicated auth guards) |
| `Redirector::guest` | Specialized login redirection seam |
| `RedirectResponse::withCookies` | Handled by HTTP cookie middleware |

### 1.4 How `setDefaults` reaches the action and response

```php
// RouteParameterBinder.php:102 — Every default is merged into route parameters:
protected function replaceDefaults(array $parameters)
{
    foreach ($parameters as $key => $value) {
        $parameters[$key] = $value ?? Arr::get($this->route->defaults, $key);
    }
    foreach ($this->route->defaults as $key => $value) {
        if (! isset($parameters[$key])) {
            $parameters[$key] = $value;
        }
    }
    return $parameters;
}

// ControllerDispatcher.php:38 — Dispatches controller method:
public function dispatch(Route $route, $controller, $method)
{
    $parameters = $this->resolveParameters($route, $controller, $method);

    if (method_exists($controller, 'callAction')) {
        return $controller->callAction($method, $parameters);
    }

    return $controller->{$method}(...array_values($parameters));
}

// DeclaredAction.php — Preserves associative string parameter names:
public function callAction(string $method, array $parameters): Response
{
    return $this->{$method}(...$parameters);
}
```

Because `Controller::callAction()` natively calls `array_values($parameters)`, standard controllers discard associative parameter names. Overriding `callAction()` inside `DeclaredAction` preserves string keys (`...$parameters`), allowing `__invoke(...$args)` to extract defaults (`model`, `target`, `call`, `redirect`, `with`) directly by name.

### 1.5 Grounding in `migrations.md` and Database Schema

Database schema tables and column definitions created during Phase 6 (`docs/declarative-schema.md`) and documented in `docs/repos/laravel/docs/migrations.md` represent the persistent **system of record** that `DeclaredAction` mutates:

1. **Auto-incrementing Primary Keys (`migrations.md:785`)**:
   `Blueprint::id('id')` defines the unsigned bigint primary key. `Router::model('todo', App\Models\Todo::class)` looks up records by this key. Mutations like `update`, `delete`, and `toggle` operate on the bound model identified by this primary key.
2. **String and Text Attributes (`migrations.md:994`, `1003`)**:
   `Blueprint::string('title')` and `Blueprint::text('description')` declare persistable string attributes. In a `call: create` or `call: update` mutation, `$request->validated()` attributes populate these columns subject to model `$fillable` mass-assignment guards.
3. **Boolean Flags & State Toggles (`migrations.md:627`)**:
   `Blueprint::boolean('completed')->default(false)` declares a boolean state column. A `call: toggle` mutation targets this exact column, flipping its boolean value (`$todo->completed = ! $todo->completed`) and dispatching `$todo->save()`.
4. **Foreign Key Constraints & Referential Integrity (`migrations.md:716`, `1571`)**:
   `Blueprint::foreignId('user_id')->constrained()->cascadeOnDelete()` enforces database-level referential integrity. When `DeclaredAction` executes `call: delete`, foreign key actions defined in the migration schema (such as `cascadeOnDelete()`) trigger automatic database cleanup of dependent child rows.
5. **Timestamps & Soft Deletes (`migrations.md:1063`, `985`)**:
   `Blueprint::timestamps()` declares `created_at` and `updated_at`. Eloquent updates these automatically on `create`, `update`, `save`, and `touch`. If the table declares `Blueprint::softDeletes()`, a `call: delete` mutation dispatches a soft delete (`deleted_at = NOW()`), while `call: restore` clears the timestamp.

### 1.6 The PHP this replaces

```php
// app/Http/Controllers/TodoController.php
namespace App\Http\Controllers;

use App\Http\Requests\CreateTodoRequest;
use App\Http\Requests\UpdateTodoRequest;
use App\Models\Todo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

final class TodoController extends Controller
{
    public function store(CreateTodoRequest $request): RedirectResponse
    {
        Todo::create($request->validated());

        return redirect()->route('todos.index')
            ->with('status', 'Todo created successfully!');
    }

    public function update(UpdateTodoRequest $request, Todo $todo): RedirectResponse
    {
        $todo->update($request->validated());

        return redirect()->route('todos.index')
            ->with('status', 'Todo updated!');
    }

    public function toggle(Todo $todo): RedirectResponse
    {
        $todo->completed = ! $todo->completed;
        $todo->save();

        return redirect()->route('todos.index');
    }

    public function destroy(Todo $todo): RedirectResponse
    {
        $todo->delete();

        return redirect()->route('todos.index')
            ->with('status', 'Todo deleted!');
    }
}
```

Replaced entirely by declarative routes in `manifest/app.yml`:

```yaml
routes:
  # 1. CREATE
  - path: "todos"
    methods: POST
    action: ZeroToProd\LaravelDeclaration\DeclaredAction
    name: todos.store
    middleware: [web]
    metadata:
      request: create-todo
    setDefaults:
      model: App\Models\Todo
      call: create
      redirect: todos.index
      with:
        status: Todo created successfully!

  # 2. UPDATE
  - path: "todos/{todo}"
    methods: PATCH
    action: ZeroToProd\LaravelDeclaration\DeclaredAction
    name: todos.update
    middleware: [web]
    metadata:
      request: update-todo
    setDefaults:
      target: todo
      call: update
      redirect: todos.index
      with:
        status: Todo updated!

  # 3. TOGGLE
  - path: "todos/{todo}/toggle"
    methods: POST
    action: ZeroToProd\LaravelDeclaration\DeclaredAction
    name: todos.toggle
    middleware: [web]
    setDefaults:
      target: todo
      call: toggle
      column: completed
      redirect: todos.index

  # 4. DELETE
  - path: "todos/{todo}"
    methods: DELETE
    action: ZeroToProd\LaravelDeclaration\DeclaredAction
    name: todos.destroy
    middleware: [web]
    setDefaults:
      target: todo
      call: delete
      redirect: todos.index
      with:
        status: Todo deleted!
```

---

## 2. Manifest schema proposal

### 2.1 Design rules

1. **Key = method name.** `call: create` → `Model::create()`, `call: update` → `$model->update()`, `call: delete` → `$model->delete()`, `route:` → `Redirector::route()`, `to:` → `Redirector::to()`, `back:` → `Redirector::back()`, `with:` → `RedirectResponse::with()`, `withInput:` → `RedirectResponse::withInput()`. Zero invented verbs (Rule 1).
2. **One seam class per Laravel base class.** `DeclaredAction extends Controller` (Rule 7 seam), mirroring `DeclaredView extends ViewController`, `DeclaredRequest extends FormRequest`, and `DeclaredModel extends Model`.
3. **Pure Tier 2 delegation over bespoke attribute DSLs.** Zero synthetic attribute classes (`Mutation`, `RedirectAction`, `FlashAction`) are introduced. Mutations execute native Eloquent methods wrapped inside `DB::transaction()` (`db:`), and redirects delegate directly to native `Redirector` (`redirect:`) and `RedirectResponse` (Rule 8).
4. **Validation strictly precedes mutation.** If `metadata.request` is declared, `app(DeclaredRequest::class)->validateResolved()` runs before any mutation executes. Invalid requests immediately redirect with validation errors; the mutation never runs.
5. **Data derives from validated request.** `create` and `update` automatically pass `$request->validated()` to the target model method unless explicit `args` are specified in `setDefaults`.
6. **`route:cache`-safe.** Route defaults contain only strings, booleans, integers, lists, and maps. No closures or object instances are stored in route defaults.

### 2.2 Values (under `setDefaults`)

| Key | Type | Description | Laravel Method | Absent Default |
|---|---|---|---|---|
| `model` | `class-string<Model>` | Target Eloquent model class for static operations | `Model::class` | `null` |
| `target` | `string` | Bound route parameter name holding the model instance | `$parameters[$target]` | `null` |
| `call` | `string` | Native method name executed on target (`create`, `update`, `delete`, `touch`, `restore`, `forceDelete`) | `$target->{$call}()` | `create` (if `model`), `update` (if `target`) |
| `args` | `map<string, mixed>` | Explicit arguments passed to mutation method (overrides request data) | Passed to mutation | `null` (`$request->validated()`) |
| `redirect` | `string` | Smart redirect: resolves to named route if exists, otherwise path | `Redirector::route()` / `to()` | `'/'` |
| `route` | `string\|array` | Explicit named route destination | `Redirector::route()` | `null` |
| `to` | `string` | Explicit URI path destination | `Redirector::to()` | `null` |
| `back` | `bool\|array` | Redirect back to previous URL | `Redirector::back()` | `false` |
| `away` | `string` | Redirect to external URL without validation | `Redirector::away()` | `null` |
| `action` | `string\|array` | Redirect to controller action | `Redirector::action()` | `null` |
| `status` | `int` | HTTP redirect status code | `RedirectResponse::__construct()` | `302` |
| `headers` | `map<string, string>` | Additional HTTP headers on redirect response | `RedirectResponse::withHeaders()` | `[]` |
| `withFragment` | `string` | Sets target URL fragment identifier | `RedirectResponse::withFragment()` | `null` |
| `with` | `map<string, mixed>` | Session flash data (e.g., status message) | `RedirectResponse::with()` | `[]` |
| `withInput` | `bool\|list<string>` | Flash request input to session store | `RedirectResponse::withInput()` | `false` |
| `withErrors` | `string\|array` | Flash error bag to session store | `RedirectResponse::withErrors()` | `null` |

### 2.3 Example YAML declarations

```yaml
routes:
  # 1. Standard POST create with session flash
  - path: "todos"
    methods: POST
    action: ZeroToProd\LaravelDeclaration\DeclaredAction
    name: todos.store
    middleware: [web]
    metadata:
      request: create-todo
    setDefaults:
      model: App\Models\Todo
      call: create
      redirect: todos.index
      with:
        status: Todo created successfully!

  # 2. PATCH update with route model binding and session flash
  - path: "todos/{todo}"
    methods: PATCH
    action: ZeroToProd\LaravelDeclaration\DeclaredAction
    name: todos.update
    middleware: [web, Illuminate\Routing\Middleware\SubstituteBindings]
    metadata:
      request: update-todo
    setDefaults:
      target: todo
      call: update
      redirect: todos.index
      with:
        status: Todo updated!

  # 3. Boolean state toggle on schema column
  - path: "todos/{todo}/toggle"
    methods: POST
    action: ZeroToProd\LaravelDeclaration\DeclaredAction
    name: todos.toggle
    middleware: [web, Illuminate\Routing\Middleware\SubstituteBindings]
    setDefaults:
      target: todo
      call: toggle
      column: completed
      back: true

  # 4. DELETE record with redirect back and flash
  - path: "todos/{todo}"
    methods: DELETE
    action: ZeroToProd\LaravelDeclaration\DeclaredAction
    name: todos.destroy
    middleware: [web, Illuminate\Routing\Middleware\SubstituteBindings]
    setDefaults:
      target: todo
      call: delete
      redirect: todos.index
      with:
        status: Todo deleted!

  # 5. Soft-delete restore operation
  - path: "todos/{todo}/restore"
    methods: POST
    action: ZeroToProd\LaravelDeclaration\DeclaredAction
    name: todos.restore
    middleware: [web, Illuminate\Routing\Middleware\SubstituteBindings]
    setDefaults:
      target: todo
      call: restore
      redirect: todos.index
      with:
        status: Todo restored!
```

### 2.4 Key to method map

| YAML Key | Target Object | Method Called | Signature |
|---|---|---|---|
| `call: create` | `class-string<Model>` | `create` | `Model::create(array $attributes = []): Model` |
| `call: update` | `Model` | `update` | `Model::update(array $attributes = [], array $options = []): bool` |
| `call: delete` | `Model` | `delete` | `Model::delete(): ?bool` |
| `call: touch` | `Model` | `touch` | `Model::touch(?string $attribute = null): bool` |
| `call: restore` | `Model` | `restore` | `Model::restore(): ?bool` |
| `call: forceDelete` | `Model` | `forceDelete` | `Model::forceDelete(): ?bool` |
| `redirect` | `Redirector` | `route` or `to` | `$redirector->route($name)` if named route exists, else `$redirector->to($path)` |
| `route` | `Redirector` | `route` | `Redirector::route(string $route, mixed $parameters = [], int $status = 302, array $headers = [])` |
| `to` | `Redirector` | `to` | `Redirector::to(string $path, int $status = 302, array $headers = [], ?bool $secure = null)` |
| `back` | `Redirector` | `back` | `Redirector::back(int $status = 302, array $headers = [], mixed $fallback = false)` |
| `away` | `Redirector` | `away` | `Redirector::away(string $path, int $status = 302, array $headers = [])` |
| `action` | `Redirector` | `action` | `Redirector::action(string\|array $action, mixed $parameters = [], int $status = 302, array $headers = [])` |
| `with` | `RedirectResponse`| `with` | `RedirectResponse::with(string\|array $key, mixed $value = null)` |
| `withInput` | `RedirectResponse`| `withInput` | `RedirectResponse::withInput(?array $input = null)` |
| `withErrors` | `RedirectResponse`| `withErrors` | `RedirectResponse::withErrors(mixed $provider, string $key = 'default')` |
| `headers` | `RedirectResponse`| `withHeaders` | `RedirectResponse::withHeaders(array $headers)` |
| `withFragment`| `RedirectResponse`| `withFragment` | `RedirectResponse::withFragment(string $fragment)` |

### 2.5 Pure Seam Architecture & Execution Algorithm

Rather than inventing bespoke attribute classes (`Mutation`, `RedirectAction`, `FlashAction`), `DeclaredAction` functions as a pure **Tier 2 (Declarative Seam)** extending `Illuminate\Routing\Controller`. Grounded in prerequisite **Tier 1 mappings** (`db.transaction`, `redirect:`, `responses:`), `DeclaredAction` coordinates execution with full database transaction safety and direct delegation to native Laravel APIs:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           DeclaredAction::__invoke                          │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │
                         1. Validate DeclaredRequest
                                       │
                                       ▼
                     ┌───────────────────────────────────┐
                     │     Stage 1: Resolve Target       │
                     │  'model' (class) or 'target' ($m) │
                     └─────────────────┬─────────────────┘
                                       │
                                       ▼
                     ┌───────────────────────────────────┐
                     │  Stage 2: Transactional Mutation  │
                     │          DB::transaction          │
                     │     create / update / delete      │
                     └─────────────────┬─────────────────┘
                                       │
                                       ▼
                     ┌───────────────────────────────────┐
                     │    Stage 3: Native Redirection    │
                     │        Redirector / Response      │
                     │  route / to / back + session flash│
                     └─────────────────┬─────────────────┘
                                       │
                                       ▼
                       return RedirectResponse instance
```

#### Refactored `src/DeclaredAction.php` Seam Implementation (Zero Synthetic Attributes)

```php
namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

class DeclaredAction extends Controller
{
    /**
     * Execute an action on the controller, preserving associative argument keys.
     *
     * @param string $method
     * @param array<string, mixed> $parameters
     */
    public function callAction($method, $parameters): Response
    {
        return $this->{$method}(...$parameters);
    }

    /**
     * Handle state-changing mutation within a transaction and return RedirectResponse.
     */
    public function __invoke(mixed ...$args): RedirectResponse
    {
        $request = request();
        $route = $request->route();

        // 1. Authorize and validate request if declared
        $validated = $route?->getMetadata('request') !== null
            ? (array) app(DeclaredRequest::class)->validated()
            : $request->all();

        // 2. Resolve target entity
        $target = null;
        if (isset($args['model']) && is_string($args['model'])) {
            if (! is_subclass_of($args['model'], Model::class)) {
                throw new LogicException("Model class [{$args['model']}] must extend " . Model::class . '.');
            }
            $target = $args['model'];
        } elseif (isset($args['target']) && is_string($args['target'])) {
            $targetParam = $args['target'];
            $resolved = $args[$targetParam] ?? $route?->parameter($targetParam);

            if (! $resolved instanceof Model) {
                throw new InvalidArgumentException(
                    "Target parameter [{$targetParam}] must resolve to an instance of " . Model::class . '.'
                );
            }
            $target = $resolved;
        } else {
            throw new LogicException("DeclaredAction requires either 'model' or 'target' to be specified in setDefaults.");
        }

        // 3. Determine mutation method and attributes
        $method = isset($args['call']) && is_string($args['call'])
            ? $args['call']
            : (is_string($target) ? 'create' : 'update');
        $attributes = isset($args['args']) && is_array($args['args']) ? $args['args'] : $validated;

        // 4. Atomic Transaction Boundary (Tier 1 db: mapping)
        DB::transaction(function () use ($target, $method, $attributes): void {
            if (is_string($target)) {
                $target::{$method}($attributes);
            } elseif ($method === 'update') {
                $target->update($attributes);
            } elseif ($method === 'touch') {
                $target->touch();
            } else {
                $target->{$method}();
            }
        });

        // 5. Redirection via Native Redirector (Tier 1 redirect: mapping)
        /** @var Redirector $redirector */
        $redirector = app('redirect');
        $status = is_numeric($args['status'] ?? null) ? (int) $args['status'] : 302;
        $headers = is_array($args['headers'] ?? null) ? $args['headers'] : [];

        $response = match (true) {
            isset($args['route']) => is_array($args['route'])
                ? $redirector->route($args['route']['name'], $args['route']['parameters'] ?? [], $status, $headers)
                : $redirector->route($args['route'], [], $status, $headers),
            isset($args['to']) => $redirector->to($args['to'], $status, $headers),
            isset($args['back']) => $redirector->back($status, $headers),
            isset($args['away']) => $redirector->away($args['away'], $status, $headers),
            isset($args['redirect']) => $redirector->to($args['redirect'], $status, $headers),
            default => $redirector->to('/', $status, $headers),
        };

        // 6. Flash Chaining via Native RedirectResponse
        if (isset($args['with']) && is_array($args['with'])) {
            $response->with($args['with']);
        }
        if (isset($args['withInput'])) {
            is_array($args['withInput']) ? $response->onlyInput(...$args['withInput']) : $response->withInput();
        }
        if (isset($args['withErrors'])) {
            $response->withErrors($args['withErrors']);
        }

        return $response;
    }
}
```

### 2.6 Edge cases & non-goals

1. **Validation Failure Handling**: When `metadata.request` fails validation, `DeclaredRequest::validateResolved()` throws `ValidationException`. Laravel's exception handler catches this and immediately generates a `302 Redirect` back to the referring page with session errors and flashed input (`migrations.md` state remains untouched). The mutation dispatcher in `DeclaredAction` is never reached.
2. **Missing or Unbound Route Parameters**: If `{todo}` cannot be found in the database, `SubstituteBindings` throws `ModelNotFoundException` (HTTP `404 Not Found`). `DeclaredAction` never receives an invalid or null model instance.
3. **Explicit Attributes vs Request Attributes**: If `args` is declared under `setDefaults`, its map takes precedence over `$request->validated()`. This enables declaring fixed mutations (e.g. `args: {completed: true}`) without requiring user input.
4. **Non-goals**:
   - Multi-database distributed sagas. Complex multi-step transactions across heterogeneous data stores belong in dedicated application service classes.
   - Client-side reactive JavaScript framework state management (Livewire / Inertia). `DeclaredAction` adheres to standard HTTP Post-Redirect-Get (PRG) patterns.
   - Non-redirect responses. API endpoints returning raw JSON responses belong to a future `DeclaredJson` seam.

---

## 3. Verification & testing strategy

### 3.1 Feature test plan (`tests/Feature/DeclaredActionTest.php`)

| Test Case | Scenario | Expected Outcome |
|---|---|---|
| `POST /todos` (Create) | Valid input, `model: App\Models\Todo`, `call: create`, `redirect: todos.index`, `with: {status: ...}` | Record created in DB (`assertDatabaseHas`), redirected to `todos.index` with status session flash |
| `PATCH /todos/{todo}` (Update) | Valid input, `target: todo`, `call: update`, `redirect: todos.index` | Record updated in DB, redirected to `todos.index` with status session flash |
| `POST /todos/{todo}/toggle` (Toggle) | Bound model, `target: todo`, `call: toggle`, `column: completed`, `back: true` | Boolean column flipped (`false` → `true`), redirected back via `Redirector::back()` |
| `DELETE /todos/{todo}` (Delete) | Bound model, `target: todo`, `call: delete`, `redirect: todos.index` | Record removed from DB (`assertDatabaseMissing`), redirected to `todos.index` |
| Validation failure | Invalid input on `POST /todos` with `metadata.request: create-todo` | `302 Redirect` back with session errors; DB has 0 new records |
| Route cache safety | Route collection serialized via `route:cache` / `setCompiledRoutes` | Declared action routes function identically after cache restore |

### 3.2 Fixture definition (`tests/Fixtures/manifest/action.yml`)

```yaml
# yaml-language-server: $schema=./../../../manifest.schema.json

schema:
  tables:
    todos:
      id: ~
      string: title
      boolean:
        column: completed
        default: false
      timestamps: ~

router:
  model:
    todo: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models\Todo

requests:
  - name: create-todo
    rules:
      title: [required, string, 'max:255']

  - name: update-todo
    rules:
      title: [sometimes, required, string, 'max:255']

routes:
  - path: "todos"
    methods: POST
    action: ZeroToProd\LaravelDeclaration\DeclaredAction
    name: todos.store
    middleware: [web]
    metadata:
      request: create-todo
    setDefaults:
      model: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models\Todo
      call: create
      redirect: todos.index
      with:
        status: Todo created successfully!

  - path: "todos/{todo}"
    methods: PATCH
    action: ZeroToProd\LaravelDeclaration\DeclaredAction
    name: todos.update
    middleware: [web, Illuminate\Routing\Middleware\SubstituteBindings]
    metadata:
      request: update-todo
    setDefaults:
      target: todo
      call: update
      redirect: todos.index
      with:
        status: Todo updated!

  - path: "todos/{todo}/toggle"
    methods: POST
    action: ZeroToProd\LaravelDeclaration\DeclaredAction
    name: todos.toggle
    middleware: [web, Illuminate\Routing\Middleware\SubstituteBindings]
    setDefaults:
      target: todo
      call: toggle
      column: completed
      redirect: todos.index

  - path: "todos/{todo}"
    methods: DELETE
    action: ZeroToProd\LaravelDeclaration\DeclaredAction
    name: todos.destroy
    middleware: [web, Illuminate\Routing\Middleware\SubstituteBindings]
    setDefaults:
      target: todo
      call: delete
      redirect: todos.index
      with:
        status: Todo deleted!
```
