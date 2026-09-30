# Declarative Architecture & Request-to-View Roadmap

Source of truth: `vendor/laravel/framework/src/Illuminate` (`laravel/framework` v13.33.0): `Routing/Router.php`, `Routing/ViewController.php`, `Routing/RedirectController.php`, `Routing/Redirector.php`, `Routing/RouteBinding.php`, `Routing/ControllerDispatcher.php`, `Routing/Controller.php`, `Routing/ImplicitRouteBinding.php`, `Http/RedirectResponse.php`, `View/Factory.php`, `View/Concerns/ManagesEvents.php`, `View/View.php`, `Support/Facades/Blade.php`, `Database/Schema/Builder.php`, `Database/Schema/Blueprint.php`, `Database/Eloquent/Model.php`, `Database/Eloquent/Builder.php`, `Foundation/Http/Kernel.php`, `Database/DatabaseManager.php`, `Contracts/Routing/ResponseFactory.php`, `Pagination/Paginator.php`.

Goal: Declare the entire application HTTP lifecycle in a single manifest — **request in, validated, bound, queried, rendered into inline Blade views, or mutated atomically and redirected** — with zero hand-written controllers, models, migrations, or template files. Every declaration adheres to the foundational design rules: **a key is a Laravel method (or property) name, a value is its argument(s), and the provider executes Laravel's native call.** String references pass through directly to Laravel's native resolvers.

---

## 1. Architectural Pipeline & Framework API Mapping

The complete request and schema lifecycle in dispatch order, mapping each stage to its owning Laravel API, manifest key, and implementation status:

| # | Stage | Laravel API | Manifest key | Status |
|---|---|---|---|---|
| 1 | Service Container | `Illuminate\Foundation\Application` | `app` | Completed |
| 2 | Configuration | `Illuminate\Support\Facades\Config` | `config` | Completed |
| 3 | Service Providers | `Illuminate\Foundation\Application::register()` | `providers` | Completed |
| 4 | HTTP Kernel Pipeline | `Illuminate\Foundation\Http\Kernel` | `kernel` | Completed |
| 5 | Database Connection & Listeners | `Illuminate\Database\DatabaseManager` | `db` | Completed |
| 6 | Database Schema Definition | `Illuminate\Database\Schema\Builder` / `Blueprint` | `schema` | Completed |
| 7 | Global Parameter Patterns | `Illuminate\Routing\Router::pattern()` | `router.pattern` | Completed |
| 8 | Route Parameter Bindings | `Illuminate\Routing\Router::model()` / `bind()` | `router.model` / `router.bind` | Completed |
| 9 | Route Match & Middleware | `Illuminate\Routing\Router::addRoute()` / `Route` | `routes` | Completed |
| 10 | Request Authorization & Validation | `Illuminate\Foundation\Http\FormRequest` / `DeclaredRequest` | `requests` / `metadata.request` | Completed |
| 11 | Reusable Query Pipelines (Read) | `Illuminate\Database\Eloquent\Builder` / `DeclaredQuery` | `queries` | Completed |
| 12 | Shared & Composed View Data | `Illuminate\View\Factory::share()` / `composer()` | `view` | Completed |
| 13 | Blade Compiler Extensions | `Illuminate\View\Compilers\BladeCompiler` | `blade` | Completed |
| 14 | Pagination Configuration | `Illuminate\Pagination\Paginator` | `pagination` | Completed |
| 15 | Response Factory Macros | `Illuminate\Contracts\Routing\ResponseFactory::macro()` | `responses` | Completed |
| 16 | Declarative View Action (Read Action) | `Illuminate\Routing\ViewController` / `DeclaredView` | `routes.action` (`DeclaredView`) | Completed |
| 17 | Inline Blade Template Rendering | `Illuminate\Support\Facades\Blade::render()` | `setDefaults.template` | Completed |
| 18 | Eloquent Model Definition | `Illuminate\Database\Eloquent\Model` / `DeclaredModel` | `models` | Completed |
| 19 | State Mutation Pipeline (Write Action) | `Illuminate\Database\Eloquent\Model` writes via `DB::transaction()` | `routes.action` (`DeclaredAction`) | Forward Roadmap (Phase 7) |
| 20 | Redirect & Session Flash (Write Response) | `Illuminate\Routing\Redirector` / `RedirectResponse` | `setDefaults.redirect` / `with` | Forward Roadmap (Phase 7) |
| 21 | Zero-PHP Dynamic Model Synthesis | `Illuminate\Database\Eloquent\Model` dynamic class loader | `models` | Forward Roadmap (Phase 9) |
| 22 | Headless REST/API Response Seam | `Illuminate\Contracts\Routing\ResponseFactory::json()` | `routes.action` (`DeclaredJson`) | Forward Roadmap (Phase 11) |

The persistent schema catalog and database tables serve as the authoritative **system of record** for entity state, while `manifest/app.yml` serves as the declarative **data source**.

### 1.1 Explicit Route Model Binding (Stage 8)

`ImplicitRouteBinding::resolveForRoute()` inspects `$route->signatureParameters(['subClass' => UrlRoutable::class])`, which depends on action method type-hints. Invokable action seams like `DeclaredView` and `DeclaredAction` define generic signatures (`__invoke(mixed ...$args)`), leaving route parameters as raw strings unless explicitly bound. `Router::substituteBindings()` resolves parameters via `$this->binders[$key]`, populated by `Router::model()` and `Router::bind()`. Explicit model bindings declared under `router.model` resolve parameter values into hydrated Eloquent model instances within the `SubstituteBindings` middleware prior to controller invocation.

### 1.2 Declarative View Dispatch & Inline Blade Rendering (Stages 16–17)

`DeclaredView` extends `Illuminate\Routing\ViewController` to provide a zero-controller read action. When invoked by the route dispatcher:
1. Merges route defaults (`setDefaults`) with matched route parameters, giving route parameters precedence.
2. Resolves query handles in `data:` via `DeclaredQuery::run()` and container callables via `app()->call()`.
3. If `setDefaults.template` is provided, dispatches to `Illuminate\Support\Facades\Blade::render()` with the merged data and returns an `Illuminate\Http\Response` with configured status and headers.
4. If `setDefaults.view` is provided, delegates to `ViewController::__invoke()` to render external template files via `ResponseFactory::view()`.

### 1.3 State Mutation Pipeline & Post-Redirect-Get Flow (Stages 19–20)

State mutations in standard Laravel web applications follow the **Post-Redirect-Get (PRG)** pattern:
1. Inbound `POST`, `PATCH`, `PUT`, or `DELETE` requests enter the middleware pipeline.
2. Route middleware runs `SubstituteBindings` to hydrate bound model parameters (e.g. `{todo}` → `App\Models\Todo`).
3. `metadata.request` executes `DeclaredRequest::validateResolved()` to authorize and validate input. Validation failures redirect back with errors automatically.
4. An invokable action seam, `DeclaredAction extends Controller`, executes the mutation (`create`, `update`, `delete`, `touch`, `restore`) inside `DB::transaction()` using validated attributes.
5. `DeclaredAction` delegates to `Illuminate\Routing\Redirector` (`route()`, `to()`, `back()`, `away()`) and chains session flash data via `RedirectResponse::with()`.

### 1.4 Declarative Database Schema Definition (Stage 6)

Zero-migration database table creation maps YAML table declarations directly to `Illuminate\Database\Schema\Blueprint` methods:
1. `Schema::create($table, Closure $callback)` receives a `Blueprint` instance.
2. Column definitions, column modifiers, indexes, and foreign key constraints map 1:1 onto fluent `Blueprint` calls.
3. Missing database tables are created idempotently via `php artisan declaration:migrate`, guarded by `Schema::hasTable($table)` checks against the database **system of record**.

---

## 2. Design Rules

1. **Key = method name.** `router.model` → `Router::model()`, `view.composer` → `Factory::composer()`, `schema.tables.todos.string` → `Blueprint::string()`, `DeclaredAction.call` → `Model::create()`. No invented verbs.
2. **Map = one call per entry, first argument is the key** (`abstract: concrete` in `app`), unless Laravel defines the map form itself: `Factory::composers()` is `callback: views`. **List = one call per item.**
3. **Pass references through when Laravel accepts strings.** `Router::bind()` takes `Class@method` (default `bind`) and `Factory::composer()` takes `Class@method` (default `compose`) natively.
4. **Wrap only where Laravel needs a Closure**, and call through `Container::call()` with **named** parameters.
5. **One seam class per Laravel base class.** `DeclaredRequest extends FormRequest`; `DeclaredView extends ViewController`; `DeclaredAction extends Controller`; `DeclaredModel extends Model`. Each seam reads its declaration from the matched route or manifest container.
6. **`route:cache` / `config:cache` safe.** Route defaults and metadata hold only strings, lists, and maps.
7. **Fail at the same place Laravel fails.** Unknown keys throw `LogicException` when the manifest is read; invalid references fail with Laravel's own exceptions upon first use.

---

## 3. Architecture & Implementation Status

### 3.1 Completed Subsystems

The foundational framework mappings and declarative pipelines are fully implemented and verified with 100% test coverage:

- **Manifest Core & Service Providers (`app`, `config`, `providers`)**: Container bindings, singletons, contextual bindings, configuration overrides, and provider registration via `AppDeclarationServiceProvider`, `ConfigDeclarationServiceProvider`, and `ProvidersDeclarationServiceProvider`.
- **HTTP Kernel Pipeline (`kernel`)**: Global middleware, middleware groups, route middleware aliases, middleware priority ordering, and lifecycle duration monitoring via `KernelDeclarationServiceProvider`.
- **Database Engine (`db`)**: Database connection resolution and query execution event listeners via `DatabaseDeclarationServiceProvider`.
- **Database Schema Management (`schema`)**: Declarative table creation, column types, column modifiers, indexes, foreign keys, and idempotent execution via `php artisan declaration:migrate` and `SchemaDeclarationServiceProvider`.
- **Blade Templating Engine (`blade`)**: Directives, custom if-conditions, component registration, anonymous component paths/namespaces, and stringable handlers via `BladeDeclarationServiceProvider`.
- **View Configuration (`view`)**: View locations, namespace paths, shared view variables, and view composers/creators via `ViewDeclarationServiceProvider`.
- **Pagination Engine (`pagination`)**: Pagination styling (Tailwind, Bootstrap 4/5) and custom view configuration via `PaginationDeclarationServiceProvider`.
- **Response Extensions (`responses`)**: Dynamic macro registration on `Illuminate\Contracts\Routing\ResponseFactory` via `ResponseDeclarationServiceProvider`.
- **Routing & Model Binding (`router`, `routes`)**: Route URI registration, HTTP verb matching, where constraints, route middleware, metadata, safe defaults, global regex patterns, and explicit model/parameter binders via `RouterDeclarationServiceProvider` and `RoutesDeclarationServiceProvider`.
- **Request Validation & Authorization (`requests`)**: Declarative form requests extending `FormRequest` via `DeclaredRequest`, validating input rules and evaluating authorization gates prior to controller dispatch.
- **Eloquent Models (`models`)**: Declarative Eloquent model configuration via `DeclaredModel` (tables, primary keys, key types, incrementing, date formats, timestamps, fillable attributes, guarded attributes, hidden/visible attributes, casts, appends, touched relations, model observers, and global query scopes).
- **Reusable Query Pipelines (`queries`)**: Declarative query pipeline execution via `DeclaredQuery`, supporting model roots, relation roots, 40+ chained `Builder` methods, and terminal execution (`get`, `first`, `paginate`, `simplePaginate`, `cursorPaginate`, `sole`, `count`, `exists`, `value`, `pluck`).
- **Declarative View Action & Inline Templates (`DeclaredView`)**: Zero-controller view rendering extending `ViewController`, resolving declared query pipelines and callable references dynamically, evaluating inline Blade templates via `Blade::render()`, and emitting composing lifecycle events.

---

### 3.2 Forward Implementation Roadmap

#### Phase 7 — `DeclaredAction` → `Illuminate\Routing\Controller` & `Redirector` [Active Scope]

Closes stages 19 and 20. Provides a zero-controller invokable action seam for state-changing HTTP requests (`POST`, `PATCH`, `PUT`, `DELETE`).

```yaml
routes:
  - uri: "todos"
    methods: POST
    action: ZeroToProd\LaravelDeclaration\DeclaredAction
    middleware: [web]
    metadata:
      request: create-todo
    setDefaults:
      model: App\Models\Todo
      call: create
      redirect: todos.index
      with:
        status: Todo created successfully!

  - uri: "todos/{todo}"
    methods: PATCH
    action: ZeroToProd\LaravelDeclaration\DeclaredAction
    middleware: [web]
    metadata:
      request: update-todo
    setDefaults:
      target: todo
      call: update
      redirect: todos.index

  - uri: "todos/{todo}"
    methods: DELETE
    action: ZeroToProd\LaravelDeclaration\DeclaredAction
    middleware: [web]
    setDefaults:
      target: todo
      call: delete
      redirect: todos.index
      with:
        status: Todo deleted!
```

| YAML key | Target / Operation | Laravel API |
|---|---|---|
| `model` | Model class-string | Class root (`App\Models\Todo`) |
| `target` | Parameter name | Bound route instance (`todo` from `{todo}`) |
| `call` | Method name | `create()`, `update()`, `delete()`, `touch()`, `restore()` |
| `redirect` | Path or route name | `Redirector::to()` or `Redirector::route()` |
| `back` | Boolean | `Redirector::back()` |
| `away` | External URL | `Redirector::away()` |
| `status` | HTTP status code | `RedirectResponse` status code (default 302) |
| `with` | Session flash data | `RedirectResponse::with($key, $value)` |
| `withInput` | Boolean | `RedirectResponse::withInput()` |
| `withErrors` | MessageBag or array | `RedirectResponse::withErrors($provider)` |

Decisions:
- **`DeclaredAction extends Controller`** (Rule 5 seam). Invoked with `__invoke(...$args)`.
- **Validation precedes mutation.** If `metadata.request` is set, `app(DeclaredRequest::class)->validateResolved()` runs before the mutation executes. Validation failures redirect back with errors natively.
- **Attributes derive from validated request.** `call: create` and `call: update` automatically pass `$request->validated()` to the target model method unless explicit arguments are configured.
- **Atomic state writes.** State mutations execute inside `DB::transaction()`.

**Deliverables:** `src/DeclaredAction.php`, `docs/declarative-action.md`, `tests/Fixtures/manifest/action.yml`, `tests/Feature/DeclaredActionTest.php`, `manifest.schema.json`.

---

#### Phase 9 — Zero-PHP Dynamic Model Synthesis → `DeclaredModel` [Active Scope]

Closes stage 21. Enables declaring Eloquent models completely inside `manifest/app.yml` without creating boilerplate PHP model files on disk.

```yaml
models:
  - class: App\Models\Todo
    table: todos
    fillable: [title, completed]
    casts:
      completed: boolean
```

Decisions:
- **Dynamic class synthesis.** When `Manifest::$models` contains a class-string that does not exist on disk, a dynamic class autoloader synthesizes a runtime subclass extending `ZeroToProd\LaravelDeclaration\DeclaredModel`.
- **Full feature parity.** Synthesized models inherit all `DeclaredModel` features: declared `table`, `fillable`, `casts`, `timestamps`, route binding via `Router::model('todo', 'App\Models\Todo')`, and query execution via `DeclaredQuery`.

**Deliverables:** Dynamic autoloader hook in `LaravelDeclarationProvider`, updates to `src/DeclaredModel.php`, `docs/declarative-model.md`, `tests/Feature/DeclaredModelTest.php`.

---

#### Phase 10 — Full-Stack Todo Application Integration Test [Active Scope]

Validates the complete single-manifest lifecycle across all subsystems in an end-to-end integration test.

**Deliverables:** Single-manifest fixture `tests/Fixtures/manifest/todo-app.yml` and test suite `tests/Feature/TodoAppIntegrationTest.php` exercising schema migration (`declaration:migrate`), model synthesis, query resolution, inline Blade rendering, request validation, atomic create mutation, and delete mutation with PRG redirect.

---

#### Phase 11 — Declarative JSON API Response Seam (`DeclaredJson`) [Future Scope]

Closes stage 22. Extends declarative actions to headless REST/API architectures via `ResponseFactory::json()`.

```yaml
routes:
  - uri: "api/todos"
    methods: GET
    action: ZeroToProd\LaravelDeclaration\DeclaredJson
    middleware: [api]
    setDefaults:
      data: all-todos
      status: 200
```

Decisions:
- **`DeclaredJson extends Controller`** (Rule 5 seam). Dispatches to `ResponseFactory::json()`.
- **Automatic serialization.** Serializes resolved `DeclaredQuery` models and collections into JSON responses.

**Deliverables:** `src/DeclaredJson.php`, `docs/declarative-json.md`, `tests/Fixtures/manifest/json.yml`, `tests/Feature/DeclaredJsonTest.php`.

---

## 4. Target Architecture (Complete Single-Manifest Application)

The unified declarative specification declaring an entire interactive Todo application in a single manifest (`manifest/app.yml`):

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
  - uri: "/"
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
  - uri: "todos"
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
  - uri: "todos/{todo}"
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

## 5. Forward Execution Plan

The remaining implementation tasks proceed in sequential order:

1. **Deliver Phase 7 (`DeclaredAction`)**:
   - Implement `src/DeclaredAction.php` extending `Illuminate\Routing\Controller`.
   - Handle target resolution (`model` class or bound route parameter `target`).
   - Wrap mutation invocations (`create`, `update`, `delete`, `touch`, `restore`) inside `DB::transaction()`.
   - Implement redirect dispatch via `Redirector` (`route()`, `to()`, `back()`, `away()`) and flash chaining (`with()`, `withInput()`, `withErrors()`).
   - Provide test coverage in `tests/Feature/DeclaredActionTest.php` with fixture `tests/Fixtures/manifest/action.yml`.

2. **Deliver Phase 9 (Zero-PHP Dynamic Model Synthesis)**:
   - Implement dynamic class autoloader in `LaravelDeclarationProvider` to intercept declared model classes in `Manifest::$models` that do not exist on disk.
   - Synthesize runtime classes extending `DeclaredModel`.
   - Provide test coverage in `tests/Feature/DeclaredModelTest.php`.

3. **Deliver Phase 10 (Full-Stack Todo Application Integration Test)**:
   - Create unified fixture `tests/Fixtures/manifest/todo-app.yml`.
   - Implement end-to-end integration test `tests/Feature/TodoAppIntegrationTest.php` verifying schema setup, dynamic model creation, query resolution, inline Blade rendering, request validation, and PRG mutations.

4. **Deliver Phase 11 (`DeclaredJson`)**:
   - Implement `src/DeclaredJson.php` extending `Controller` for REST API endpoints.
   - Provide test coverage in `tests/Feature/DeclaredJsonTest.php`.

---

## 6. Definition of Done (Every Phase)

1. DataModel in `src/` with `public const string` + property per key, `Describe` defaults matching native absence behavior; unknown keys throw `LogicException` on read.
2. `Manifest` property and dedicated service provider applying declarations in the exact lifecycle stage Laravel expects.
3. Dedicated documentation in `docs/declarative-<feature>.md` with Laravel API source of truth, lifecycle position, schema mappings, and failure semantics.
4. Schema definition updated in `manifest.schema.json`.
5. Fixture YAML under `tests/Fixtures/manifest/` and feature test under `tests/Feature/*Test.php`.
6. Full test suite passing with **100% code coverage**, zero PHPStan errors at maximum level, zero Rector issues, Pint code styling, and green backwards compatibility checks (`composer check`).

---

## 7. Non-goals

- A custom expression language in manifest values. Computed values must be references to standard PHP callables.
- Replacing application controllers for complex business workflows. Hand-written controllers remain fully supported alongside declared routes.
- Bundling client-side JavaScript frameworks (Livewire, Inertia, Vue, React) inside the manifest. Manifest view rendering relies on native Blade and HTML.
- Distributed transaction saga orchestration. Multi-database distributed transactions belong in dedicated application services.
