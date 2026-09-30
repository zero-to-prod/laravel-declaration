# Roadmap — Declarative Request → View & Todo Application

Source of truth: `vendor/laravel/framework/src/Illuminate` (`laravel/framework` v13.33.0): `Routing/Router.php`, `Routing/ViewController.php`, `Routing/RedirectController.php`, `Routing/Redirector.php`, `Routing/RouteBinding.php`, `Routing/ControllerDispatcher.php`, `Routing/Controller.php`, `Routing/ImplicitRouteBinding.php`, `Http/RedirectResponse.php`, `View/Factory.php`, `View/Concerns/ManagesEvents.php`, `View/View.php`, `Support/Facades/Blade.php`, `Database/Schema/Builder.php`, `Database/Schema/Blueprint.php`, `Database/Eloquent/Model.php`, `Database/Eloquent/Builder.php`, `Foundation/Http/Kernel.php`.

Goal: one manifest declares the whole HTTP path — **request in, validated, bound, shaped into view data, rendered, or mutated and redirected** — with no hand-written controller, model class, migration file, or template file. Every new block follows the established design rules: **a key is a Laravel method (or property) name, a value is its argument(s), and the provider applies it with Laravel's own call.** Anything that must be PHP is a **reference string**, resolved exactly as Laravel resolves it.

---

## 1. The pipeline and what already covers it

The full request and schema lifecycle, in dispatch order, with the Laravel API that owns each stage:

| # | Stage | Laravel API | Manifest key | Status |
|---|---|---|---|---|
| 1 | Container | `Application` | `app` | done |
| 2 | Config | `Config::set()` | `config` | done |
| 3 | Providers | `Application::register()` | `providers` | done |
| 4 | HTTP Kernel pipeline | `Http\Kernel` middleware, groups, priority | `kernel` | done (Phase 5) |
| 5 | Database schema definition | `Schema::create()` / `Blueprint` | `schema` | Phase 6 |
| 6 | Eloquent model defaults + dynamic synthesis | `Model` properties, observers, scopes, dynamic `DeclaredModel` | `models` | done (expanded in Phase 9) |
| 7 | Global parameter patterns | `Router::pattern()` / `patterns()` | `router` | done (Phase 1) |
| 8 | Route match + route middleware | `Router::addRoute()` + `Route` builders | `routes` | done |
| 9 | Parameter → model / value | `Router::bind()` / `Router::model()` | `router` | done (Phase 1) |
| 10 | Authorize + validate | `FormRequest` | `requests` + `metadata.request` | done |
| 11 | Reusable query pipelines (Read) | `Eloquent\Builder` chaining + terminals | `queries` | done (Phase 4) |
| 12 | State mutation pipeline (Write) | `Model::create()` / `update()` / `delete()` / `Builder` write methods | `routes.action` (`DeclaredAction`) | Phase 7 |
| 13 | Redirect + session flash (Write response) | `Redirector::route()` / `back()` / `to()` + `RedirectResponse::with()` | `setDefaults.redirect` / `status` / `with` | Phase 7 |
| 14 | View action (Read action) | `Router::view()` → `ViewController` | `routes.action` + `setDefaults` | done (`DeclaredView`) |
| 15 | Dynamic view data | `ViewController` `data` | `DeclaredView` | done (Phase 3) |
| 16 | Inline template rendering | `Blade::render($template, $data)` | `setDefaults.template` | Phase 8 |
| 17 | Shared / composed view data | `View\Factory::share()` / `composer()` / `creator()` | `view` | done (Phase 2) |
| 18 | View lookup | `View\Factory::addLocation()` / `addNamespace()` | `view` | done (Phase 2) |
| 19 | Response status + headers | `ResponseFactory::view()` / `ResponseFactory::make()` | `setDefaults.status` / `headers` | done (`DeclaredView`) |

The gaps previously blocking a self-contained todo application — **database schema creation**, **state-changing mutations (POST/PATCH/DELETE)**, **redirect responses**, **inline Blade templates**, and **zero-file model declaration** — are addressed by Phases 6 through 9.

### 1.1 Why stage 9 needs explicit binding

`ImplicitRouteBinding::resolveForRoute()` walks `$route->signatureParameters(['subClass' => UrlRoutable::class])`: the **action's type-hints**. `ViewController::__invoke(...$args)` and invokable controllers hint nothing, so `{todo}` stays the raw string `"1"`. `Router::substituteBindings()` instead reads `$this->binders[$key]`, populated by `Router::bind()` / `Router::model()`, and needs no type-hint. It runs in the `SubstituteBindings` middleware, which the `web` and `api` groups include (`Foundation/Configuration/Middleware.php`), so a declared route opts in with `middleware: [web]`.

### 1.2 Why stage 14 needs no hand-written controller

`Router::view()` is sugar over the existing `routes` keys:

```php
// Router::view($uri, $view, $data = [], $status = 200, array $headers = [])
$this->match(['GET', 'HEAD'], $uri, '\Illuminate\Routing\ViewController')
    ->setDefaults(['view' => $view, 'data' => $data, 'status' => $status, 'headers' => $headers]);
```

`ViewController::__invoke(...$args)` merges every route parameter that is not `view`, `data`, `status` or `headers` into `data` (route parameters win), then calls `ResponseFactory::view()`.

### 1.3 Why stages 12–13 require `DeclaredAction`

State mutations in standard Laravel web applications follow the **Post-Redirect-Get (PRG)** pattern:
1. `POST`, `PATCH`, or `DELETE` requests enter the pipeline.
2. Route middleware runs `SubstituteBindings` to bind route parameters (`{todo}` → `App\Models\Todo`).
3. `metadata.request` triggers `DeclaredRequest` to authorize and validate the input.
4. An invokable action seam, `DeclaredAction`, invokes the requested model or builder mutation (`create`, `update`, `delete`, `touch`, `restore`) within `DB::transaction()` passing validated attributes.
5. `DeclaredAction` dispatches a redirect response via `Illuminate\Routing\Redirector` (`route()`, `back()`, `to()`) and flashes optional status data via `RedirectResponse::with()`.

### 1.4 Why stage 5 requires declarative `Blueprint` schema mapping

Running an application entirely from the manifest without external migration files requires declarative database table creation. `Illuminate\Database\Schema\Builder::create($table, Closure $callback)` accepts a `Closure` receiving an `Illuminate\Database\Schema\Blueprint` instance. Each entry under `schema.tables.<table_name>` directly invokes the corresponding `Blueprint` method (`id()`, `string()`, `boolean()`, `timestamps()`).

---

## 2. Design rules carried forward

1. **Key = method name.** `router.model` → `Router::model()`, `view.composer` → `Factory::composer()`, `schema.tables.todos.string` → `Blueprint::string()`, `DeclaredAction.call` → `Model::create()`. No invented verbs.
2. **Map = one call per entry, first argument is the key** (`abstract: concrete` in `app`), unless Laravel defines the map form itself: `Factory::composers()` is `callback: views`. **List = one call per item.**
3. **Pass references through when Laravel accepts strings.** `Router::bind()` takes `Class@method` (default `bind`) and `Factory::composer()` takes `Class@method` (default `compose`) natively.
4. **Wrap only where Laravel needs a Closure**, and then call through `Container::call()` with **named** parameters, as `app.extend`, the hooks and `DeclaredRequest` already do.
5. **One seam class per Laravel base class.** `DeclaredRequest extends FormRequest`; `DeclaredView extends ViewController`; `DeclaredAction extends Controller`; `DeclaredModel extends Model`. Each reads its declaration from the matched route or manifest container.
6. **`route:cache` / `config:cache` safe.** Route defaults and metadata hold only strings, lists and maps.
7. **Fail at the same place Laravel fails.** Unknown keys throw `LogicException` when the manifest is read; bad references fail with Laravel's own exception at first use.

---

## 3. Phases

Each phase is independently shippable, and each is **one DataModel + one provider loop + one doc + one fixture + one Feature test** (§6).

### Phase 0 — `Router::view()` through existing route keys [Superseded by Phase 3]

Functionally available via native Laravel `Illuminate\Routing\ViewController`. Superseded by **Phase 3** (`DeclaredView`), which provides safe defaults (`data: []`, `status: 200`, `headers: []`) and resolves dynamic references and query handles.

### Phase 1 — `router:` block → `Illuminate\Routing\Router` [Completed]

Closes stage 9 (and stage 7). Global patterns, explicit model binding, custom binder classes registered via `RouterDeclarationServiceProvider`.

**Deliverables completed:** `src/Router.php`, `Manifest::$router`, `RouterDeclarationServiceProvider`, `docs/declarative-router.md`, `docs/declarative-router-bindings.md`, `tests/Fixtures/manifest/router.yml`, `tests/Feature/RouterRegistrationTest.php`, schema.

### Phase 2 — `view:` block → `Illuminate\View\Factory` [Completed]

Closes stages 17 and 18: view locations, namespaces, shared data, view composers, and creators registered via `ViewDeclarationServiceProvider`.

**Deliverables completed:** `src/View.php`, `Manifest::$view`, `ViewDeclarationServiceProvider`, `docs/declarative-view.md`, `tests/Fixtures/manifest/view.yml`, `tests/Feature/ViewRegistrationTest.php`, schema.

### Phase 3 — `DeclaredView` → `Illuminate\Routing\ViewController` [Completed]

Closes stage 14, and joins stage 10 to stage 15. Reads defaults, validates requests via `metadata.request`, resolves query handles and container references, and renders views.

**Deliverables completed:** `src/DeclaredView.php`, `docs/declarative-view-data.md`, `tests/Fixtures/manifest/view-data.yml`, `tests/Feature/DeclaredViewTest.php`, schema.

### Phase 4 — `queries:` → `Illuminate\Database\Eloquent\Builder` [Completed]

Closes stage 11. Reusable Eloquent query pipelines with 40+ builder methods, model and relation roots, terminal execution, and automatic resolution in `DeclaredView`.

**Deliverables completed:** `src/Query.php`, `src/DeclaredQuery.php`, `Manifest::$queries`, `docs/declarative-query.md`, `tests/Fixtures/manifest/queries.yml`, `tests/Feature/DeclaredQueryTest.php`, schema.

### Phase 5 — `kernel:` block → `Illuminate\Foundation\Http\Kernel` [Completed]

Closes stage 4. Durable HTTP middleware pipelines, groups, aliases, priorities, and request duration lifecycle handlers registered via `KernelDeclarationServiceProvider`.

**Deliverables completed:** `src/Kernel.php`, `Manifest::$kernel`, `KernelDeclarationServiceProvider`, `docs/declarative-kernel.md`, `tests/Fixtures/manifest/kernel.yml`, `tests/Feature/KernelRegistrationTest.php`, schema.

### Phase 6 — `schema:` block → `Illuminate\Database\Schema\Builder` & `Blueprint` [Active Scope]

Closes stage 5. Enables zero-migration declarative database table creation directly from `manifest/app.yml`.

```yaml
schema:
  tables:
    todos:
      id: ~                                    # -> $blueprint->id()
      string: title                            # -> $blueprint->string('title')
      boolean:                                 # -> $blueprint->boolean('completed')->default(false)
        column: completed
        default: false
      timestamps: ~                            # -> $blueprint->timestamps()
```

| YAML key | `Blueprint` method | Value | Applied |
|---|---|---|---|
| `id` | `id($column = 'id')` | `string \| null` | `callAfterResolving('db')` or boot verification |
| `string` | `string($column, $length = null)` | `string \| array{column: string, length?: int}` | `Schema::create()` / `Blueprint` |
| `boolean` | `boolean($column)` | `string \| array{column: string, default?: bool}` | `Blueprint` |
| `text` | `text($column)` | `string` | `Blueprint` |
| `integer` | `integer($column)` | `string \| array{column: string, default?: int}` | `Blueprint` |
| `foreignId` | `foreignId($column)` | `string \| array{column: string, constrained?: bool}` | `Blueprint` |
| `timestamps` | `timestamps()` | `null \| true` | `Blueprint` |

Decisions:
- **Keys map 1:1 onto `Illuminate\Database\Schema\Blueprint` methods.**
- **Automatic table verification.** Applied via `Schema::hasTable($table)` guard on boot or via dedicated `php artisan declaration:migrate` command, creating missing tables idempotently without external migration files.

**Deliverables:** `src/Schema.php` (DataModel), `Manifest::$schema`, `SchemaDeclarationServiceProvider`, `docs/declarative-schema.md`, `tests/Fixtures/manifest/schema.yml`, `tests/Feature/SchemaRegistrationTest.php`, `manifest.schema.json` `schema` definition.

### Phase 7 — `DeclaredAction` → `Illuminate\Routing\Controller` & `Redirector` [Active Scope]

Closes stages 12 and 13. Provides a zero-controller invokable action seam for state-changing HTTP requests (`POST`, `PATCH`, `PUT`, `DELETE`).

```yaml
routes:
  - path: "todos"
    methods: POST
    action: ZeroToProd\LaravelDeclaration\DeclaredAction
    middleware: [web]
    metadata:
      request: create-todo                     # validated DeclaredRequest
    setDefaults:
      model: App\Models\Todo                   # target model class
      call: create                             # -> Todo::create($request->validated())
      redirect: /                              # -> redirect('/') or route name
      with: {status: 'Todo created!'}          # -> with('status', 'Todo created!')

  - path: "todos/{todo}"
    methods: PATCH
    action: ZeroToProd\LaravelDeclaration\DeclaredAction
    middleware: [web]
    setDefaults:
      target: todo                             # bound route parameter {todo}
      call: update                             # -> $todo->update($request->validated())
      redirect: /

  - path: "todos/{todo}"
    methods: DELETE
    action: ZeroToProd\LaravelDeclaration\DeclaredAction
    middleware: [web]
    setDefaults:
      target: todo                             # bound route parameter {todo}
      call: delete                             # -> $todo->delete()
      redirect: /
```

| YAML key | Target / Operation | Laravel API |
|---|---|---|
| `model` | Model class-string | Class root (`App\Models\Todo`) |
| `target` | Parameter name | Bound route instance (`todo` from `{todo}`) |
| `call` | Method name | `create()`, `update()`, `delete()`, `touch()`, `restore()` |
| `redirect` | Path or route name | `Redirector::to()` or `Redirector::route()` |
| `back` | Boolean | `Redirector::back()` |
| `status` | HTTP status code | `RedirectResponse` status code (default 302) |
| `with` | Session flash data | `RedirectResponse::with($key, $value)` |

Decisions:
- **`DeclaredAction extends Controller`** (Rule 5 seam). Invoked with `__invoke(...$args)`.
- **Validation precedes mutation.** If `metadata.request` is set, `app(DeclaredRequest::class)->validateResolved()` runs before the mutation executes. Validation failures redirect with errors natively.
- **Attributes derive from validated request.** `call: create` and `call: update` automatically pass `$request->validated()` to the target model method unless explicit arguments are configured.

**Deliverables:** `src/DeclaredAction.php`, `docs/declarative-action.md`, `tests/Fixtures/manifest/action.yml`, `tests/Feature/DeclaredActionTest.php`, schema.

### Phase 8 — Inline Template Rendering → `Blade::render()` in `DeclaredView` [Active Scope]

Closes stage 16. Allows single-file full-stack manifests by declaring Blade templates directly in YAML without requiring external `.blade.php` files on disk.

```yaml
routes:
  - path: "/"
    methods: GET
    action: ZeroToProd\LaravelDeclaration\DeclaredView
    middleware: [web]
    setDefaults:
      template: |
        <h1>Todos</h1>
        <form method="POST" action="/todos">
          @csrf
          <input name="title" placeholder="New todo..." required>
          <button type="submit">Add</button>
        </form>
        <ul>
          @foreach ($todos as $todo)
            <li>
              <span>{{ $todo->title }}</span>
              <form method="POST" action="/todos/{{ $todo->id }}" style="display:inline">
                @csrf @method('DELETE')
                <button type="submit">Delete</button>
              </form>
            </li>
          @endforeach
        </ul>
      data:
        todos: all-todos
```

Decisions:
- **`setDefaults.template` takes precedence over `view`.** Evaluated via native `Illuminate\Support\Facades\Blade::render($template, $data, deleteCachedView: true)` returning an `Illuminate\Http\Response`.
- **Backwards compatible.** If `view` is specified and `template` is omitted, `DeclaredView` delegates to `ViewController::__invoke()` as originally implemented in Phase 3.

**Deliverables:** Updates to `src/DeclaredView.php`, `docs/declarative-view-data.md`, fixture and feature test in `tests/Feature/DeclaredViewTest.php`.

### Phase 9 — Zero-PHP Dynamic Model Synthesis → `DeclaredModel` [Active Scope]

Closes stage 6. Enables declaring Eloquent models completely inside `manifest/app.yml` without creating boilerplate PHP model files.

```yaml
models:
  - class: App\Models\Todo
    table: todos
    fillable: [title, completed]
    casts:
      completed: boolean
```

Decisions:
- **Dynamic class synthesis.** When `Manifest::$models` contains a class-string that does not exist on disk, a dynamic class resolution hook synthesizes an alias or subclass extending `ZeroToProd\LaravelDeclaration\DeclaredModel`.
- **Full feature parity.** Synthesized models inherit all `DeclaredModel` features: declared `table`, `fillable`, `casts`, `timestamps`, route binding via `Router::model('todo', 'App\Models\Todo')`, and query execution via `DeclaredQuery`.

**Deliverables:** Dynamic autoloader hook in `LaravelDeclarationProvider`, updates to `src/DeclaredModel.php`, `docs/declarative-model.md`, and feature test in `tests/Feature/DeclaredModelTest.php`.

---

## 4. End state (Complete Declarative Todo Application)

The unified pipeline declaring an entire interactive Todo application in a single manifest:

```yaml
schema:
  tables:
    todos:
      id: ~
      string: title
      boolean:
        column: completed
        default: false
      timestamps: ~

models:
  - class: App\Models\Todo
    table: todos
    fillable: [title, completed]
    casts:
      completed: boolean

router:
  model:
    todo: App\Models\Todo

requests:
  - name: create-todo
    rules:
      title: [required, string, 'max:255']

queries:
  - name: all-todos
    from: App\Models\Todo
    latest: created_at
    get: [*]

routes:
  # 1. READ: Display Todos and Input Form
  - path: "/"
    methods: GET
    action: ZeroToProd\LaravelDeclaration\DeclaredView
    name: todos.index
    middleware: [web]
    setDefaults:
      data:
        todos: all-todos
      template: |
        <!DOCTYPE html>
        <html>
        <head><title>Declarative Todo</title></head>
        <body>
          <h1>Todo List</h1>
          @if (session('status'))
            <p style="color: green">{{ session('status') }}</p>
          @endif
          <form method="POST" action="/todos">
            @csrf
            <input type="text" name="title" placeholder="What needs to be done?" required>
            <button type="submit">Add Todo</button>
          </form>
          <ul>
            @forelse ($todos as $todo)
              <li>
                <span style="{{ $todo->completed ? 'text-decoration: line-through;' : '' }}">
                  {{ $todo->title }}
                </span>
                <form method="POST" action="/todos/{{ $todo->id }}" style="display:inline">
                  @csrf @method('DELETE')
                  <button type="submit">Delete</button>
                </form>
              </li>
            @empty
              <li>No todos yet!</li>
            @endforelse
          </ul>
        </body>
        </html>

  # 2. CREATE: Store new Todo
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
        status: Todo added successfully!

  # 3. DELETE: Remove existing Todo
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

### Complete Request Lifecycles

1. **Viewing Todos (`GET /`)**:
   - `Route` matches `/`.
   - `DeclaredView` resolves `all-todos` via `DeclaredQuery::run('all-todos')`, executing `Todo::query()->latest('created_at')->get()`.
   - `Blade::render()` compiles and renders the inline `template` with `{todos}`.
   - Clean HTML renders in the browser. Zero controller classes, zero view files.

2. **Creating a Todo (`POST /todos`)**:
   - `Route` matches `/todos`.
   - `web` middleware provides session and CSRF verification.
   - `metadata.request: create-todo` engages `DeclaredRequest`, validating `['title' => ['required', 'string', 'max:255']]`.
   - `DeclaredAction` receives `$request->validated()` and calls `App\Models\Todo::create($attributes)`.
   - `Redirector::route('todos.index')` returns an `Illuminate\Http\RedirectResponse` with flashed session status `'Todo added successfully!'`. Zero controller classes.

3. **Deleting a Todo (`DELETE /todos/1`)**:
   - `Route` matches `/todos/{todo}`.
   - `SubstituteBindings` invokes `Router::model('todo', App\Models\Todo::class)`, binding `$todo = Todo::findOrFail(1)`.
   - `DeclaredAction` calls `$todo->delete()`.
   - Returns redirect to `todos.index` with session status `'Todo deleted!'`. Zero controller classes.

---

## 5. Where We Stand vs. What Is Left To Do

### 5.1 Where We Stand

The original request-to-view pipeline and core framework subsystems are **completed, tested, and green**:

1. **Phase 1 (`router:`)**: Global patterns, explicit model binding, custom binder classes registered via `RouterDeclarationServiceProvider`.
2. **Phase 2 (`view:`)**: View paths, namespaces, shared data, view composers, and creators registered via `ViewDeclarationServiceProvider`.
3. **Phase 3 (`DeclaredView`)**: Zero-controller action extending `ViewController`, resolving container references and request-validated data.
4. **Phase 4 (`queries:`)**: Reusable Eloquent query pipelines with 40+ builder methods, model and relation roots, terminal execution, and automatic resolution in `DeclaredView`.
5. **Phase 5 (`kernel:`)**: Durable HTTP middleware pipelines, groups, aliases, priorities, and request duration lifecycle handlers registered via `KernelDeclarationServiceProvider`.
6. **Eloquent Models (`models:`)**: Declarative Eloquent models via `DeclaredModel` (tables, primary keys, fillable attributes, casts, global scopes, observers, route key names).
7. **Modular Service Providers (`src/Providers/`)**: Concern providers cleanly isolated and invoked via container method injection, replacing monolithic provider loops.
8. **Tooling & Quality**: 100% test coverage, PHPStan max level, Rector, Pint formatting, backwards-compatibility validation (`bc-check`), and Model Context Protocol (MCP) server integration.

### 5.2 What Is Left To Do (Todo Application Scope)

To fulfill the expanded scope of building a complete Todo application entirely in the manifest without writing external PHP files:

1. **Phase 6 — Declarative Schema (`schema:`)**:
   - Implement `src/Schema.php` DataModel and `SchemaDeclarationServiceProvider` mapping YAML table definitions to `Blueprint` column calls.
2. **Phase 7 — Declarative Action & Redirects (`DeclaredAction`)**:
   - Grounded in prerequisite Tier 1 mappings for `db:` (`DatabaseManager::transaction()`) and `redirect:` (`Redirector`), implement `src/DeclaredAction.php` extending `Illuminate\Routing\Controller` to execute state-changing mutations (`create`, `update`, `delete`, `touch`, `restore`) inside `DB::transaction()` and return native `RedirectResponse` instances with session flash. Zero synthetic attribute classes.
3. **Phase 8 — Inline Template Rendering in `DeclaredView`**:
   - Add `template:` evaluation in `src/DeclaredView.php` via `Illuminate\Support\Facades\Blade::render()`.
4. **Phase 9 — Zero-PHP Dynamic Model Synthesis**:
   - Add dynamic class synthesis for declared models in `Manifest::$models` so classes extending `DeclaredModel` require no physical file.
5. **Full-Stack Todo App Integration Test**:
   - End-to-end integration test (`tests/Feature/TodoAppIntegrationTest.php`) verifying schema creation, model synthesis, read pipeline, create mutation, and delete mutation within a single manifest fixture (`tests/Fixtures/manifest/todo-app.yml`).
6. **Future Extension — `DeclaredJson`**:
   - Zero-controller JSON API response action extending `ResponseFactory::json()` for headless REST/API parity.

---

## 6. Definition of done (every phase)

1. DataModel in `src/` with one `public const string` + property per key, `Describe` defaults matching "absent" behavior; unknown keys throw `LogicException` at read (as `App`).
2. `Manifest` property; provider applies it in the phase Laravel would (documented in the doc's §1.1).
3. `docs/declarative-<feature>.md` in the house format: §1 Laravel API (source of truth + lifecycle), §2 schema (design rule, references, full example, key → method map, registration algorithm, non-goals).
4. README section with the complete structure block; `manifest.schema.json` definition.
5. Fixture YAML under `tests/Fixtures/manifest/` + `tests/Feature/*Test.php`.
6. `composer check` green: lint, rector, phpstan, **100% coverage**, bc-check.

---

## 7. Non-goals

- A general expression language in values. Anything computed is a reference.
- Replacing controllers for custom domain logic. A hand-written controller keeps working beside every phase.
- Client-side reactive JavaScript frameworks (Livewire, Inertia, Vue, React) inside YAML. Manifest view rendering relies on native Blade and HTML.
- Distributed transaction saga orchestrations. Complex multi-database transactions belong in dedicated application services.
