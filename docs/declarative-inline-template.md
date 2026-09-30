# Declarative Inline Template Rendering — `Illuminate\Support\Facades\Blade::render()` Dynamic Dispatch, View Evaluation & Response Generation in `DeclaredView`

Source of truth: `vendor/laravel/framework/src/Illuminate/View/Compilers/BladeCompiler.php` (`BladeCompiler.php:340`), with:
- `Illuminate\Support\Facades\Blade.php` (`Blade.php:15`)
- `Illuminate\View\Component.php` (`Component.php:142`, `174`, `196`)
- `Illuminate\Routing\ViewController.php` (`ViewController.php:10`)
- `Illuminate\Contracts\Routing\ResponseFactory.php` (`ResponseFactory.php:18`)
- `Illuminate\Routing\ResponseFactory.php` (`ResponseFactory.php:59`, `73`)
- `Illuminate\Http\Response.php` (`Response.php:16`)
- `Illuminate\View\Factory.php` (`Factory.php:38`)
- `Illuminate\Routing\RouteParameterBinder.php` (`RouteParameterBinder.php:102`)
- `Illuminate\Routing\ControllerDispatcher.php` (`ControllerDispatcher.php:38`)
- `Illuminate\Routing\Route.php` (`Route.php:35`)
- `Illuminate\Routing\Router.php` (`Router.php:38`)

Laravel documentation references:
- `docs/repos/laravel/docs/blade.md` (Rendering Inline Blade Templates:1922, Rendering Blade Fragments:1943, Displaying Data:69, CSRF Field:39, Method Field:40, Validation Errors:41, Components:757, Conditional Classes:463, The `@once` Directive:626)
- `docs/repos/laravel/docs/views.md` (Creating & Rendering Views:12, Passing Data to Views:53, View Composers:144, Optimizing Views:218)
- `docs/repos/laravel/docs/responses.md` (Attaching Headers to Responses:62, Attaching Cookies:78, View Responses:143, Response Objects:37)
- `docs/repos/laravel/docs/routing.md` (Route Parameters:271, View Routes:133, Form Method Spoofing:254)
- `docs/repos/laravel/docs/migrations.md` (Auto-incrementing IDs:785, Column Modifiers:1214, Boolean Types:627, String Types:994, Timestamps:1063, Soft Deletes:985, Foreign Keys:716)
- `docs/repos/laravel/docs/database.md` (Running Queries:112, Database Transactions:382)

Grounding documentation:
- `docs/declarative-request-to-view-roadmap.md` §1 Stage 16, §3 Phase 8, §4 End State.
- `docs/declarative-view-data.md`
- `docs/declarative-action.md`
- `docs/declarative-schema.md`

Goal: a declarative view route whose **`setDefaults` keys declare an inline Blade template string (`template`) or view name (`view`), view data bindings (`data`), HTTP response status (`status`), response headers (`headers`), and temporary cache file cleanup (`deleteCachedView`)**. This is Phase 8 of [declarative-request-to-view-roadmap.md](declarative-request-to-view-roadmap.md): Stage 16 (inline template rendering), joining Stage 5/6 (database schema & Eloquent models as persistent **systems of record**), Stage 9 (bound route parameter), Stage 10 (`DeclaredRequest` validation), and Stage 11 (`DeclaredQuery` execution) to outgoing HTTP response generation. The database table and column schemas defined in Phase 6 (`docs/declarative-schema.md`) and migration definitions (`docs/repos/laravel/docs/migrations.md`) serve as the **system of record** for persistent entity state. The package extends `DeclaredView` and introduces **dynamic dispatch** via `RenderAction` (§2.5) to polymorphically dispatch rendering to inline Blade templates (`template`) or view files (`view`) without procedural branching or hardcoded switch statements.

---

## 1. Public API of `BladeCompiler::class`, `Blade::class`, `ViewController::class`, `ResponseFactory::class`

### 1.1 Lifecycle position (when the declaration runs)

```
// 1. Route Registration (manifest/app.yml -> registerRoutes())
$Router->addRoute(['GET', 'HEAD'], '/', DeclaredView::class)
    ->middleware(['web'])
    ->setDefaults([
        'template'         => '<h1>{{ $title }}</h1>',               // Inline Blade template string
        'data'             => ['title' => 'Declarative App'],        // Dynamic query or reference data
        'status'           => 200,                                   // HTTP response status
        'headers'          => ['X-Rendered-By' => 'DeclaredView'],   // Outgoing response headers
        'deleteCachedView' => true,                                  // Clean up temporary compiled Blade file
    ]);

// 2. Inbound HTTP Request Lifecycle
HTTP GET /
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
    │       └─► Parameter replacement (e.g. {todo} bound to Todo instance)
    │
    └─► ControllerDispatcher::dispatch($route, $controller, $method)
            │
            ├─► DeclaredView::callAction('__invoke', $parameters)
            │       │   (preserves associative string keys: 'template', 'view', 'data', 'status', 'headers')
            │       ▼
            ├─► Stage 10: DeclaredRequest validation (if metadata.request set)
            │       app(DeclaredRequest::class)->validateResolved()
            │       On failure: throws ValidationException -> redirects back with errors (no rendering runs)
            │       On success: passes validated input into request container binding
            │       ▼
            ├─► Stage 11 & 15: View Data Pipeline & Dynamic Query Resolution
            │       Iterates setDefaults.data:
            │           - If string is a query name: DeclaredQuery::run($name, $parameters)
            │           - If string is a container callable (Class@method): app()->call($target, $parameters)
            │           - Else: literal scalar or array passed untouched
            │       Route parameters merged: URL route parameters win over defaults
            │       ▼
            ├─► Stage 16: Dynamic Dispatch Rendering via RenderAction
            │       (new RenderAction)->apply($responseFactory, $args, $mergedData)
            │       │
            │       ├─► Target: 'template' (Precedence: template wins over view)
            │       │       Blade::render($template, $mergedData, deleteCachedView: true)
            │       │       │
            │       │       ├─► Anonymous Component wraps template string
            │       │       ├─► Writes compiled view to storage/framework/views/{hash}.blade.php
            │       │       ├─► ViewFactory evaluates compiled PHP with resolved view data
            │       │       └─► If deleteCachedView: unlinks temporary compiled view file
            │       │       ▼
            │       │       Returns ResponseFactory::make($html, $status, $headers)
            │       │
            │       └─► Target: 'view' (Fallback backwards compatibility)
            │               ResponseFactory::view($view, $mergedData, $status, $headers)
            │       ▼
            └─► Returns Illuminate\Http\Response (HTTP 200 / 201 / custom status)
```

Consequences, each verified against v13.33.0 with Testbench:

1. **Read at dispatch, per request.** Dynamic view data and inline template evaluation execute on each incoming HTTP request during `ControllerDispatcher::dispatch()`. No pre-compiled HTML is baked into the route cache, ensuring dynamic database state and session variables render accurately on every request.
2. **Validation strictly precedes rendering.** If `metadata.request` is declared on the route, `app(DeclaredRequest::class)->validateResolved()` runs before any `DeclaredQuery` executes and before `Blade::render()` compiles the template. Invalid requests terminate immediately with an HTTP 422 JSON response or an HTTP 302 redirect with flashed validation errors. Zero template compilation occurs on invalid requests.
3. **Route parameter binding precedes rendering.** `SubstituteBindings` runs in the middleware pipeline prior to controller dispatch. Route parameters bound via `Router::model()` or `Router::bind()` (Phase 1) are already hydrated into Eloquent model instances and merged into the Blade view data array.
4. **`route:cache`-safe.** The `template` string, `data` map, `status` code, and `headers` list declared in `setDefaults` are primitive PHP types (strings, integers, arrays). No closures or objects reside in `Route::$defaults`, guaranteeing full compatibility with `php artisan route:cache`.
5. **Temporary compiled file management.** `Blade::render($template, $data, deleteCachedView: true)` automatically unlinks the generated PHP file in `storage/framework/views` after rendering, preventing filesystem bloat in serverless or read-only container environments.
6. **Precedence rules.** `template` takes explicit precedence over `view`. When both `template` and `view` are present in `setDefaults`, `RenderAction` dynamically dispatches to the inline template renderer, ignoring the external view file. When `template` is omitted, `DeclaredView` transparently delegates to `ResponseFactory::view()`, preserving backwards compatibility with Phase 3 view routes.

### 1.2 Properties

**Internal state on `Illuminate\View\Compilers\BladeCompiler`:**

| Property | Type | Visibility | Role in Declaration |
|---|---|---|---|
| `$customDirectives` | `array<string, Closure\|callable>` | `protected` | Registered custom directives (`@csrf`, `@method`, `@error`) compiled during rendering (`BladeCompiler.php:49`) |
| `$conditions` | `array<string, Closure\|callable>` | `protected` | Registered conditional statements evaluated during compilation (`BladeCompiler.php:56`) |
| `$prepareStringsForCompilationUsing` | `array<int, callable>` | `protected` | Callbacks executed on template strings before compilation (`BladeCompiler.php:63`) |
| `$echoFormat` | `string` | `protected` | Format string used for compiled double-brace echo statements (`BladeCompiler.php:70`) |
| `$componentAliases` | `array<string, string>` | `protected` | Aliases for Blade components rendered inside inline templates (`BladeCompiler.php:77`) |

**Internal state on `Illuminate\View\Component`:**

| Property | Type | Visibility | Role in Declaration |
|---|---|---|---|
| `$bladeViewCache` | `array<string, string>` (static) | `protected` | In-memory cache mapping template string hashes to temporary file paths (`Component.php:176`) |

**Internal state on `Illuminate\View\Compilers\Compiler`:**

| Property | Type | Visibility | Role in Declaration |
|---|---|---|---|
| `$files` | `Illuminate\Filesystem\Filesystem` | `protected` | Filesystem instance reading and writing compiled views (`Compiler.php:16`) |
| `$cachePath` | `string` | `protected` | Directory path where compiled templates reside (`storage/framework/views`) (`Compiler.php:23`) |
| `$shouldCache` | `bool` | `protected` | Determines whether compiled view files are cached on disk (`Compiler.php:37`) |
| `$compiledExtension` | `string` | `protected` | Extension of compiled view files (`'php'`) (`Compiler.php:44`) |

**Internal state on `Illuminate\Routing\ViewController`:**

| Property | Type | Visibility | Role in Declaration |
|---|---|---|---|
| `$response` | `Illuminate\Contracts\Routing\ResponseFactory` | `protected` | Factory instantiating HTTP responses (`ViewController.php:17`) |

**Internal state on `Illuminate\Routing\ResponseFactory`:**

| Property | Type | Visibility | Role in Declaration |
|---|---|---|---|
| `$view` | `Illuminate\Contracts\View\Factory` | `protected` | View factory rendering external `.blade.php` files (`ResponseFactory.php:25`) |

### 1.3 Public methods

#### 1.3.1 Declaration targets: Inline Template & View Render Dispatch

**Declaration targets: Template & View Execution**

| Method | Signature | Effect | Source |
|---|---|---|---|
| `Blade::render` | `(string $string, array $data = [], bool $deleteCachedView = false): string` | Compiles raw Blade markup to PHP, evaluates with data, and returns rendered HTML string | `BladeCompiler.php:340` |
| `BladeCompiler::render` | `($string, $data = [], $deleteCachedView = false)` | Instantiates anonymous `Component`, resolves view via `ViewFactory`, and unlinks temporary file if requested | `BladeCompiler.php:340` |
| `Component::resolveView` | `(): ViewContract\|Htmlable\|Closure\|string` | Resolves component template string into a view name or compiled temporary file | `Component.php:142` |
| `Component::createBladeViewFromString` | `($factory, $contents): string` | Generates unique SHA/XXH hash for template content and registers `__components` view file | `Component.php:196` |
| `ResponseFactory::make` | `($content = '', $status = 200, array $headers = []): Response` | Creates an `Illuminate\Http\Response` with rendered HTML string, HTTP status code, and headers | `ResponseFactory.php:59` |
| `ResponseFactory::view` | `($view, $data = [], $status = 200, array $headers = []): Response` | Resolves external `.blade.php` file via `ViewFactory` and wraps in an HTTP response | `ResponseFactory.php:73` |

#### 1.3.2 Not declaration targets

| Method | Why no declaration key |
|---|---|
| `BladeCompiler::compileString` | Returns raw un-evaluated PHP string (`<?php echo ... ?>`), not evaluated HTML output. |
| `BladeCompiler::compile` | Requires an on-disk source file path; inline templates exist solely in memory and YAML. |
| `BladeCompiler::renderComponent` | Requires pre-instantiated `Illuminate\View\Component` instance; `Blade::render()` manages component wrapping automatically. |
| `BladeCompiler::directive` / `if` | Registers compiler extensions at boot; covered by service provider boot bindings, not route defaults. |
| `ResponseFactory::json` | Returns JSON response; roadmap future extension (`DeclaredJson`). |
| `ResponseFactory::noContent` | Returns empty 204 response; not an HTML view rendering path. |
| `ResponseFactory::stream` | Streams download responses; orthogonal to view rendering. |

### 1.4 How `setDefaults` reaches the template and response

```php
// 1. Manifest declaration under `setDefaults`
setDefaults:
  template: |
    <h1>Todos</h1>
    @foreach ($todos as $todo)
      <p>{{ $todo->title }}</p>
    @endforeach
  data:
    todos: all-todos
  status: 200
  headers:
    X-Custom-Header: value
  deleteCachedView: true

// 2. Route registration in Router (Router.php:287)
$route->setDefaults([
    'template'         => $templateString,
    'data'             => ['todos' => 'all-todos'],
    'status'           => 200,
    'headers'          => ['X-Custom-Header' => 'value'],
    'deleteCachedView' => true,
]);

// 3. RouteParameterBinder::replaceDefaults() (RouteParameterBinder.php:102)
// Inbound route parameters and defaults are merged into an associative array:
$parameters = [
    'template'         => $templateString,
    'data'             => ['todos' => 'all-todos'],
    'status'           => 200,
    'headers'          => ['X-Custom-Header' => 'value'],
    'deleteCachedView' => true,
    ...$urlParameters,
];

// 4. ControllerDispatcher::dispatch() (ControllerDispatcher.php:38)
// DeclaredView::callAction('__invoke', $parameters) passes named keys:
DeclaredView::__invoke(...$args);

// 5. Data Resolution Pipeline in DeclaredView::__invoke()
$resolvedData = [
    'todos' => DeclaredQuery::run('all-todos', $parameters), // Resolves Eloquent Collection
];
$mergedData = array_merge($resolvedData, $routeParameters);

// 6. Dynamic Dispatch via RenderAction (RenderAction.php:28)
$response = (new RenderAction)->apply($this->response, $args, $mergedData);

// 7. RenderAction::template() (RenderAction.php:48)
$content = Blade::render($args['template'], $mergedData, deleteCachedView: true);
return $responseFactory->make($content, $args['status'], $args['headers']);
```

### 1.5 Grounding in `migrations.md` and Database Schema

Database schema migrations (`docs/repos/laravel/docs/migrations.md`) define the table columns and data types that act as the persistent **system of record** for all application state. Declarative inline templates in `DeclaredView` act as the presentation layer that renders this persistent state and generates interactive HTML forms targeting state-changing mutations:

1. **Schema Column Mapping to Blade Expressions (`migrations.md:425–1214`)**:
   - **Primary Key (`id()`, `migrations.md:785`)**: Rendered in HTML element identifiers and form action URLs:
     `<form method="POST" action="/todos/{{ $todo->id }}">`.
   - **String / Text Columns (`string()`, `migrations.md:994`; `text()`, `migrations.md:1003`)**: Rendered via double-curly echo syntax with automatic HTML entity escaping to prevent cross-site scripting:
     `<span>{{ $todo->title }}</span>`, `<p>{{ $todo->description }}</p>`.
   - **Boolean Columns (`boolean()`, `migrations.md:627`)**: Rendered in conditional attributes and styles:
     `<span style="{{ $todo->completed ? 'text-decoration: line-through;' : '' }}">{{ $todo->title }}</span>`,
     `<input type="checkbox" {{ $todo->completed ? 'checked' : '' }}>`.
   - **Timestamps (`timestamps()`, `migrations.md:1063`)**: Rendered using Carbon formatting helpers on Eloquent models:
     `<small>{{ $todo->created_at->diffForHumans() }}</small>`.
   - **Foreign Keys (`foreignId()`, `migrations.md:716`)**: Rendered inside form select dropdowns and relation listings:
     `<option value="{{ $user->id }}" {{ $todo->user_id === $user->id ? 'selected' : '' }}>{{ $user->name }}</option>`.
   - **Soft Deletes (`softDeletes()`, `migrations.md:985`)**: Rendered via conditional directives checking trash status:
     `@if ($todo->trashed()) <span class="badge">Archived</span> @endif`.

2. **Form Method Spoofing and CSRF Directives (`blade.md:39–40`, `routing.md:254`)**:
   HTML forms in browsers natively support only `GET` and `POST`. Inline Blade templates declared in `manifest/app.yml` leverage Blade directives to bind forms directly to state mutations executed by `DeclaredAction` (`docs/declarative-action.md`):
   - `@csrf`: Compiles to `<input type="hidden" name="_token" value="...">`, satisfying Laravel's CSRF verification middleware (`ValidateCsrfToken`).
   - `@method('PATCH')`: Compiles to `<input type="hidden" name="_method" value="PATCH">`, dispatching update mutations on target records.
   - `@method('DELETE')`: Compiles to `<input type="hidden" name="_method" value="DELETE">`, dispatching deletion mutations on target records.

3. **Session Flash & Validation Feedback (`blade.md:41`, `responses.md:289`)**:
   When a state mutation completes or validation fails, `DeclaredAction` flashes data into the session store. Inline Blade templates immediately render this state:
   - `@if (session('status')) <div class="alert">{{ session('status') }}</div> @endif`
   - `@error('title') <span class="error">{{ $message }}</span> @enderror`

### 1.6 The PHP this replaces

#### Traditional Imperative Approach (Multiple Files)

In standard Laravel applications, rendering an inline view or dedicated template requires three disjoint files across controllers, routes, and views:

```php
// 1. Controller: app/Http/Controllers/TodoViewController.php
namespace App\Http\Controllers;

use App\Models\Todo;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TodoViewController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $todos = Todo::query()->latest('created_at')->get();

        return response()->view('todos.index', [
            'todos' => $todos,
        ], 200, ['Cache-Control' => 'no-cache']);
    }
}

// 2. View: resources/views/todos/index.blade.php
<!DOCTYPE html>
<html>
<head><title>Todos</title></head>
<body>
    <h1>Todo List</h1>
    <form method="POST" action="/todos">
        @csrf
        <input name="title" required>
        <button type="submit">Add</button>
    </form>
    <ul>
        @foreach ($todos as $todo)
            <li>{{ $todo->title }}</li>
        @endforeach
    </ul>
</body>
</html>

// 3. Route: routes/web.php
use App\Http\Controllers\TodoViewController;
use Illuminate\Support\Facades\Route;

Route::get('/', TodoViewController::class)->name('todos.index');
```

#### Declarative Single-Manifest Representation

With Phase 8 inline template rendering, the controller class, template file, and routing configuration collapse into a single declarative route entry in `manifest/app.yml`:

```yaml
routes:
  - path: "/"
    methods: GET
    action: ZeroToProd\LaravelDeclaration\DeclaredView
    name: todos.index
    middleware: [web]
    setDefaults:
      data:
        todos: all-todos
      status: 200
      headers:
        Cache-Control: no-cache
      template: |
        <!DOCTYPE html>
        <html>
        <head><title>Todos</title></head>
        <body>
          <h1>Todo List</h1>
          <form method="POST" action="/todos">
            @csrf
            <input name="title" required>
            <button type="submit">Add</button>
          </form>
          <ul>
            @foreach ($todos as $todo)
              <li>{{ $todo->title }}</li>
            @endforeach
          </ul>
        </body>
        </html>
```

Zero hand-written controller classes. Zero physical `.blade.php` files on disk. Completely self-contained, cacheable, and statically analyzable.

---

## 2. Manifest schema proposal

### 2.1 Design rules

1. **Key = method or parameter name.** `template` matches `Blade::render($string)`, `data` matches `Blade::render($string, $data)` and `ViewController::$args['data']`, `status` and `headers` match `ResponseFactory::make($content, $status, $headers)` and `ResponseFactory::view($view, $data, $status, $headers)`. `deleteCachedView` matches `Blade::render()`'s third parameter.
2. **Dynamic dispatch over monolithic branching.** Rendering strategies (`template`, `view`) are encapsulated in a dedicated `RenderAction` attribute class. Methods are invoked via dynamic polymorphic dispatch (`$this->{$renderer}(...)`), eliminating nested procedural `if`/`else` trees.
3. **Template takes precedence over view.** If both `template` and `view` are specified under `setDefaults`, `template` is dispatched first. If `template` is omitted, `view` executes for backwards compatibility.
4. **Wrap only where Laravel needs a Closure.** Query references (`queries:`) and container callables (`Class@method`) resolve via `DeclaredQuery::run()` and `Container::call()` with named route parameters.
5. **One seam class per Laravel base class.** `DeclaredView extends ViewController`. It reads its declaration directly from the matched route's `$args` array populated by `RouteParameterBinder`.
6. **`route:cache`-safe.** All default parameters under `setDefaults` are primitive strings, integers, lists, and associative maps. No runtime closures are stored in the route definition.
7. **Fail at the same place Laravel fails.** Unknown route keys throw `LogicException` during manifest hydration; malformed Blade syntax throws `ParseError` or `ViewException` at first render with Laravel's native compilation diagnostics.

### 2.2 Values (under `setDefaults`)

| YAML key | Type | Default | Description | Laravel API |
|---|---|---|---|---|
| `template` | `string` | `null` | Raw multiline Blade markup string | `Blade::render($string, $data, $deleteCachedView)` |
| `view` | `string \| list<string>` | `null` | External view name or fallback list (used when `template` is absent) | `ResponseFactory::view($view, $data, $status, $headers)` |
| `data` | `array<string, mixed>` | `[]` | View variables, query names, or container references | `Blade::render()` `$data` / `ViewController` `$data` |
| `status` | `int` | `200` | HTTP response status code | `ResponseFactory::make()` / `view()` `$status` |
| `headers` | `array<string, string>` | `[]` | HTTP response headers | `ResponseFactory::make()` / `view()` `$headers` |
| `deleteCachedView` | `bool` | `true` | Unlink compiled Blade file from disk after render | `Blade::render(..., deleteCachedView: bool)` |

### 2.3 Example YAML declarations

#### Example 1: Pure Inline Blade Template with Flash and CSRF

```yaml
routes:
  - path: "/"
    methods: GET
    action: ZeroToProd\LaravelDeclaration\DeclaredView
    name: home
    middleware: [web]
    setDefaults:
      status: 200
      headers:
        X-Frame-Options: SAMEORIGIN
      template: |
        <!DOCTYPE html>
        <html lang="en">
        <head>
          <meta charset="utf-8">
          <title>Declarative Portal</title>
          <style>body { font-family: sans-serif; padding: 2rem; }</style>
        </head>
        <body>
          <h1>Welcome to Declarative Laravel</h1>
          @if (session('status'))
            <p style="color: green;">{{ session('status') }}</p>
          @endif
          <form method="POST" action="/submit">
            @csrf
            <input type="text" name="name" placeholder="Enter name..." required>
            <button type="submit">Submit</button>
          </form>
        </body>
        </html>
```

#### Example 2: Interactive Todo List with Read Query Pipeline and Schema Columns

```yaml
routes:
  - path: "todos"
    methods: GET
    action: ZeroToProd\LaravelDeclaration\DeclaredView
    name: todos.index
    middleware: [web]
    setDefaults:
      data:
        todos: all-todos
      template: |
        <div class="todo-app">
          <h2>Active Tasks</h2>
          @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
          @endif

          <form method="POST" action="/todos">
            @csrf
            <input type="text" name="title" placeholder="New todo item..." required>
            <button type="submit">Create</button>
          </form>

          <ul>
            @forelse ($todos as $todo)
              <li>
                <span class="{{ $todo->completed ? 'completed' : 'pending' }}">
                  {{ $todo->title }} (created {{ $todo->created_at->format('M j') }})
                </span>

                <form method="POST" action="/todos/{{ $todo->id }}/toggle" style="display:inline;">
                  @csrf @method('PATCH')
                  <button type="submit">{{ $todo->completed ? 'Mark Incomplete' : 'Complete' }}</button>
                </form>

                <form method="POST" action="/todos/{{ $todo->id }}" style="display:inline;">
                  @csrf @method('DELETE')
                  <button type="submit">Delete</button>
                </form>
              </li>
            @empty
              <li>No tasks available!</li>
            @endforelse
          </ul>
        </div>
```

#### Example 3: Bound Route Parameters, Dynamic References & Validation Errors

```yaml
routes:
  - path: "users/{user}/profile"
    methods: GET
    action: ZeroToProd\LaravelDeclaration\DeclaredView
    middleware: [web, Illuminate\Routing\Middleware\SubstituteBindings]
    metadata:
      request: view-profile-request
    setDefaults:
      data:
        stats: App\Services\UserStats@calculate
      template: |
        <div class="profile-card">
          <h1>Profile: {{ $user->name }}</h1>
          <p>Email: {{ $user->email }}</p>
          <p>Account Created: {{ $user->created_at->toFormattedDateString() }}</p>

          <div class="stats">
            <span>Reputation: {{ $stats['reputation'] }}</span>
            <span>Total Posts: {{ $stats['post_count'] }}</span>
          </div>

          @if ($errors->any())
            <div class="errors">
              @foreach ($errors->all() as $error)
                <p style="color: red;">{{ $error }}</p>
              @endforeach
            </div>
          @endif
        </div>
```

#### Example 4: Backwards-Compatible External View Fallback

```yaml
routes:
  - path: "legacy/dashboard"
    methods: GET
    action: ZeroToProd\LaravelDeclaration\DeclaredView
    setDefaults:
      view: dashboard.index
      data:
        title: Legacy Dashboard
      status: 200
      headers:
        Cache-Control: private, max-age=3600
```

### 2.4 Key to method map

| YAML key | Target Class | Target Method / Property | Signature | Source File & Line |
|---|---|---|---|---|
| `template` | `Illuminate\Support\Facades\Blade` | `Blade::render()` | `($string, $data = [], $deleteCachedView = false): string` | `BladeCompiler.php:340` |
| `view` | `Illuminate\Routing\ResponseFactory` | `ResponseFactory::view()` | `($view, $data = [], $status = 200, array $headers = []): Response` | `ResponseFactory.php:73` |
| `data` | `Illuminate\Routing\ViewController` | `ViewController::$args['data']` | `array<string, mixed>` | `ViewController.php:37` |
| `status` | `Illuminate\Routing\ResponseFactory` | `ResponseFactory::make()` | `($content = '', $status = 200, array $headers = []): Response` | `ResponseFactory.php:59` |
| `headers` | `Illuminate\Routing\ResponseFactory` | `ResponseFactory::make()` | `($content = '', $status = 200, array $headers = []): Response` | `ResponseFactory.php:59` |
| `deleteCachedView` | `Illuminate\View\Compilers\BladeCompiler` | `BladeCompiler::render()` | `bool $deleteCachedView = false` | `BladeCompiler.php:340` |

### 2.5 Dynamic dispatch architecture & execution algorithm

The rendering pipeline in `DeclaredView` delegates view generation to `ZeroToProd\LaravelDeclaration\Attributes\RenderAction`. Matching the design pattern established by `RedirectAction` (`docs/declarative-action.md`) and `Mutation`, `RenderAction` inspects the declaration arguments and dynamically dispatches rendering polymorphically across ordered renderers:

```
RenderAction::apply($response, $args, $data)
    │
    ├─► Checks self::RENDERERS = ['template', 'view']
    │
    ├─► Match 'template':
    │       $this->template($response, $args['template'], $data, $status, $headers, $args)
    │       ├─► Blade::render($template, $data, deleteCachedView: true)
    │       └─► $response->make($html, $status, $headers)
    │
    ├─► Match 'view':
    │       $this->view($response, $args['view'], $data, $status, $headers, $args)
    │       └─► $response->view($view, $data, $status, $headers)
    │
    └─► Neither set:
            throws LogicException("DeclaredView requires either 'template' or 'view'...")
```

#### Proposed Dynamic Dispatch Attribute Implementation (`src/Attributes/RenderAction.php`)

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Attributes;

use Attribute;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Blade;
use InvalidArgumentException;
use LogicException;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
class RenderAction
{
    /** @var list<string> */
    private const array RENDERERS = ['template', 'view'];

    /**
     * @param  ResponseFactory  $response
     * @param  array<string, mixed>  $args
     * @param  array<string, mixed>  $data
     */
    public function apply(ResponseFactory $response, array $args, array $data): Response
    {
        $status = is_numeric($args['status'] ?? null) ? (int) $args['status'] : 200;
        $headers = is_array($args['headers'] ?? null) ? $args['headers'] : [];

        foreach (self::RENDERERS as $renderer) {
            if (isset($args[$renderer])) {
                return $this->{$renderer}($response, $args[$renderer], $data, $status, $headers, $args);
            }
        }

        throw new LogicException(
            "DeclaredView requires either 'template' or 'view' to be specified in setDefaults."
        );
    }

    /**
     * @param  ResponseFactory  $response
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $headers
     * @param  array<string, mixed>  $args
     */
    protected function template(
        ResponseFactory $response,
        mixed $template,
        array $data,
        int $status,
        array $headers,
        array $args = []
    ): Response {
        if (! is_string($template)) {
            throw new InvalidArgumentException('The `template` declaration must be a string.');
        }

        $deleteCachedView = ! isset($args['deleteCachedView']) || (bool) $args['deleteCachedView'];

        $content = Blade::render($template, $data, deleteCachedView: $deleteCachedView);

        return $response->make($content, $status, $headers);
    }

    /**
     * @param  ResponseFactory  $response
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $headers
     * @param  array<string, mixed>  $args
     */
    protected function view(
        ResponseFactory $response,
        mixed $view,
        array $data,
        int $status,
        array $headers,
        array $args = []
    ): Response {
        if (! is_string($view) && ! is_array($view)) {
            throw new InvalidArgumentException('The `view` declaration must be a string or an array.');
        }

        return $response->view($view, $data, $status, $headers);
    }
}
```

#### Proposed `src/DeclaredView.php` Seam Implementation

```php
<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Http\Response;
use Illuminate\Routing\ViewController;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use ZeroToProd\LaravelDeclaration\Attributes\RenderAction;

class DeclaredView extends ViewController
{
    /**
     * @param  string  $method
     * @param  array<string, mixed>  $parameters
     */
    public function callAction($method, $parameters): Response
    {
        return $this->{$method}(...$parameters);
    }

    public function __invoke(mixed ...$args): Response
    {
        $args += ['data' => [], 'status' => 200, 'headers' => []];

        $routeParameters = array_filter($args, static function (string|int $key): bool {
            return ! in_array(
                $key,
                ['template', 'view', 'data', 'status', 'headers', 'deleteCachedView'],
                true
            );
        }, ARRAY_FILTER_USE_KEY);

        $parameters = [
            ...$routeParameters,
            'request' => request()->route()?->getMetadata('request') === null
                ? request()
                : app(DeclaredRequest::class),
        ];

        /** @var array<string, mixed> $data */
        $data = $args['data'];
        $manifest = app(Manifest::class);

        $resolvedData = array_map(
            static function (mixed $value) use ($parameters, $manifest): mixed {
                if (is_string($value) && $manifest->queries->has($value)) {
                    return DeclaredQuery::run($value, $parameters);
                }

                return is_string($value) && str_contains(Str::before($value, '@'), '\\')
                    ? app()->call($value, $parameters)
                    : $value;
            },
            $data,
        );

        $mergedData = array_merge($resolvedData, $routeParameters);

        return (new RenderAction)->apply($this->response, $args, $mergedData);
    }
}
```

### 2.6 Edge cases & non-goals

1. **Compilation directory write permissions.** `Blade::render()` writes temporary `.blade.php` files into `config('view.compiled')` (typically `storage/framework/views`). Environments with read-only filesystems must ensure `view.compiled` points to a writable temporary directory (such as `/tmp`).
2. **Deterministic hashing and collision avoidance.** `Component::createBladeViewFromString()` calculates `hash('xxh128', $contents)` to name temporary view files. The XXH128 hashing algorithm is collision-resistant and non-cryptographically performant.
3. **Route parameter precedence over data defaults.** Route parameters from the URI (e.g. `{user}`) overwrite defaults declared in `data:`, identical to Laravel's native `ViewController::__invoke()` behavior (`$args['data'] = array_merge($args['data'], $routeParameters)`).
4. **Malicious code execution in templates.** Declarative templates are authored by application developers inside repository-managed `manifest/app.yml` files. User-supplied input passed into `$data` is automatically escaped by double-curly braces (`{{ $input }}`). Raw unescaped echoing (`{!! $input !!}`) must be used cautiously, matching standard Blade security practices (`blade.md:129`).
5. **Non-goals**:
   - Inventing a new templating language. All syntax within `template:` is standard, 100% native Blade supported by Laravel's `BladeCompiler`.
   - Client-side reactive rendering inside YAML. Single-page application logic (React, Vue, Svelte) belongs in dedicated frontend builds.
   - Replacing hand-written controllers when complex procedural orchestration is required. `DeclaredView` coexists with standard Laravel controllers and routes.

---

## 3. Verification & testing strategy

### 3.1 Feature test plan (`tests/Feature/DeclaredViewTest.php`)

| Test Case | Scenario | Expected Outcome |
|---|---|---|
| `renders inline blade templates with literal data` | Route with `template: 'Hello {{ $name }}'` and `data: [name: World]` | HTTP 200, response body contains `Hello World`. |
| `renders inline blade templates with DeclaredQuery` | Route with `template: '@foreach ($todos as $t){{ $t->title }}@endforeach'` and `data: [todos: all-todos]` | HTTP 200, query pipeline executes and outputs model titles. |
| `merges route parameters into inline template data` | Route `hello/{name}` with `template: 'Hi {{ $name }}'` | HTTP 200, URL parameter `{name}` rendered in template. |
| `applies declared status and custom headers` | Route with `template: 'Created'`, `status: 201`, `headers: [X-App: Declared]` | HTTP 201, header `X-App: Declared` present on response. |
| `gives template precedence over view` | Route with both `template: 'Inline'` and `view: external.view` | HTTP 200, renders `Inline` without calling external view file. |
| `delegates to external view when template is absent` | Route with `view: greeting` and no `template` key | HTTP 200, backwards compatible external view renders. |
| `validates DeclaredRequest before compiling template` | Route with `metadata: [request: my-request]` and invalid query params | HTTP 422 JSON / HTTP 302 redirect, zero template rendering occurs. |
| `unlinks temporary compiled view files` | Route with `deleteCachedView: true` | Verify temporary file in `storage/framework/views` is deleted after response generation. |
| `throws LogicException when neither template nor view is declared` | Route with `setDefaults: [data: [foo: bar]]` | Throws `LogicException` indicating missing template/view declaration. |

### 3.2 Fixture definition (`tests/Fixtures/manifest/view-template.yml`)

```yaml
# yaml-language-server: $schema=./../../../manifest.schema.json

router:
  model:
    user: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing\User

requests:
  - name: title-filter
    rules:
      q: [nullable, string, 'min:2']

routes:
  # 1. Basic inline template rendering
  - path: "inline/greeting"
    methods: GET
    action: ZeroToProd\LaravelDeclaration\DeclaredView
    setDefaults:
      template: "Hello, {{ $name }}!"
      data:
        name: Ada Lovelace

  # 2. Inline template with status and custom headers
  - path: "inline/custom-response"
    methods: GET
    action: ZeroToProd\LaravelDeclaration\DeclaredView
    setDefaults:
      status: 201
      headers:
        X-Custom-Engine: BladeRender
      template: "Resource successfully created."

  # 3. Inline template with route parameter merging
  - path: "inline/users/{user}"
    methods: GET
    action: ZeroToProd\LaravelDeclaration\DeclaredView
    middleware: [Illuminate\Routing\Middleware\SubstituteBindings]
    setDefaults:
      template: "User Profile: {{ $user->name ?? 'Guest' }}"

  # 4. Precedence: template takes precedence over view
  - path: "inline/precedence"
    methods: GET
    action: ZeroToProd\LaravelDeclaration\DeclaredView
    setDefaults:
      template: "Inline Template Won"
      view: greeting

  # 5. Form directives rendering schema columns
  - path: "inline/form"
    methods: GET
    action: ZeroToProd\LaravelDeclaration\DeclaredView
    middleware: [web]
    setDefaults:
      template: |
        <form method="POST" action="/submit">
          @csrf
          <input name="title">
          <button type="submit">Send</button>
        </form>
```
