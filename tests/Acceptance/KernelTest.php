<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\EnsureTokenIsValid;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\EnsureUserIsSubscribed;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\GroupFirst;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\GroupPrepended;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\GroupSecond;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\High;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\Low;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\PostBindings;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\PreBindings;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\Recorder;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\RecorderA;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\RecorderB;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\TerminatingMiddleware;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Models\User;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Middleware\MiddlewareLog;

// Acceptance tests for the manifest `kernel:` block — docs/declarative-kernel-acceptance-test-plan.md.
// Every behavior is sourced from docs/repos/laravel/docs/middleware.md, the system of record,
// corroborated by lifecycle.md, requests.md, routing.md and container.md as cited per test. Each
// `kernel:` key is the identically-named Illuminate\Foundation\Http\Kernel method counterpart of
// one documented Middleware-configuration call.
//
// Plan §9 gaps have no acceptance test here: G-1 (prependTo/appendToMiddlewarePriority — the
// unanchored priority insertions are undocumented), G-2 (whenRequestLifecycleIsLongerThan — zero
// matches in the vendored docs), and G-3–G-7 (documented behavior the declared surface cannot
// express: array priority anchors, replace/remove, default-group auto-application, a shared
// handle/terminate instance via container singleton, mutating an undefined group).

beforeEach(function (): void {
    MiddlewareLog::reset();
    PreBindings::reset();
    PostBindings::reset();
    TerminatingMiddleware::reset();
});

// AT-01 — middleware.md — Global Middleware: "If you want a middleware to run during every HTTP
// request to your application, you may append it to the global middleware stack".
it('runs middleware appended to the global stack during every HTTP request', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls:
          - method: afterResolving
            args:
              - Illuminate\Foundation\Http\Kernel
              - - method: pushMiddleware
                  args: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\Recorder]
          - method: afterResolving
            args:
              - Illuminate\Routing\Router
              - - method: addRoute
                  args:
                    uri: recorder/one
                    methods: GET
                    action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Controllers\EchoController
                - method: addRoute
                  args:
                    uri: recorder/two
                    methods: GET
                    action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Controllers\EchoController@alternate
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $this->get('/recorder/one')->assertOk()->assertContent('route-output');

    $this->get('/recorder/two')->assertOk()->assertContent('alternate-route-output');

    expect(MiddlewareLog::entries())->toBe([Recorder::class, Recorder::class]);
});

// AT-02 — middleware.md — Global Middleware: "The `append` method adds the middleware to the end
// of the list of global middleware. If you would like to add a middleware to the beginning of the
// list, you should use the `prepend` method." requests.md — Input Trimming and Normalization
// supplies the default-stack members whose effects bracket both positions.
it('places prepended middleware at the beginning of the global list and appended middleware at its end', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls:
          - method: afterResolving
            args:
              - Illuminate\Foundation\Http\Kernel
              - - method: prependMiddleware
                  args: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\FirstSnapshotter]
                - method: pushMiddleware
                  args: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\LastSnapshotter]
          - method: afterResolving
            args:
              - Illuminate\Routing\Router
              - - method: addRoute
                  args:
                    uri: snapshot
                    methods: GET
                    action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Controllers\SnapshotEchoController
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $this->get('/snapshot?pad=%20%20pad%20%20&empty=')->assertOk()
        ->assertJsonPath('first.pad', '  pad  ')
        ->assertJsonPath('first.empty', '')
        ->assertJsonPath('last.pad', 'pad')
        ->assertJsonPath('last.empty', null);
});

// AT-03 — middleware.md — Manually Managing Laravel's Default Global Middleware: "you may provide
// Laravel's default stack of global middleware to the `use` method" — the doc's example stack
// (the uncommented documented default members) plus the plan's declared Recorder.
it('runs the documented default stack provided to setGlobalMiddleware as the global stack', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls:
          - method: afterResolving
            args:
              - Illuminate\Foundation\Http\Kernel
              - - method: setGlobalMiddleware
                  args:
                    - - Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks
                      - Illuminate\Http\Middleware\TrustProxies
                      - Illuminate\Http\Middleware\HandleCors
                      - Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance
                      - Illuminate\Http\Middleware\ValidatePostSize
                      - Illuminate\Foundation\Http\Middleware\TrimStrings
                      - Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull
                      - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\Recorder
          - method: afterResolving
            args:
              - Illuminate\Routing\Router
              - - method: addRoute
                  args:
                    uri: snapshot
                    methods: GET
                    action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Controllers\SnapshotEchoController
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $this->get('/snapshot?pad=%20%20pad%20%20&empty=')->assertOk()
        ->assertJsonPath('input.pad', 'pad')
        ->assertJsonPath('input.empty', null);

    expect(MiddlewareLog::entries())->toBe([Recorder::class])
        ->and(app(KernelContract::class)->getGlobalMiddleware())->toBe([
            InvokeDeferredCallbacks::class,
            TrustProxies::class,
            HandleCors::class,
            PreventRequestsDuringMaintenance::class,
            ValidatePostSize::class,
            TrimStrings::class,
            ConvertEmptyStringsToNull::class,
            Recorder::class,
        ]);
});

// AT-04 — middleware.md — Manually Managing Laravel's Default Global Middleware: "Then, you may
// adjust the default middleware stack as necessary" — the doc's adjustment expressed by omission;
// requests.md — Disabling Input Normalization observes the same from the remove side.
it('treats the manually provided stack as the entire global stack', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls:
          - method: afterResolving
            args:
              - Illuminate\Foundation\Http\Kernel
              - - method: setGlobalMiddleware
                  args:
                    - - Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks
                      - Illuminate\Http\Middleware\TrustProxies
                      - Illuminate\Http\Middleware\HandleCors
                      - Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance
                      - Illuminate\Http\Middleware\ValidatePostSize
          - method: afterResolving
            args:
              - Illuminate\Routing\Router
              - - method: addRoute
                  args:
                    uri: snapshot
                    methods: GET
                    action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Controllers\SnapshotEchoController
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $this->get('/snapshot?pad=%20%20pad%20%20&empty=')->assertOk()
        ->assertJsonPath('input.pad', '  pad  ')
        ->assertJsonPath('input.empty', '');

    expect(app(KernelContract::class)->getGlobalMiddleware())->toBe([
        InvokeDeferredCallbacks::class,
        TrustProxies::class,
        HandleCors::class,
        PreventRequestsDuringMaintenance::class,
        ValidatePostSize::class,
    ]);
});

// AT-05 — middleware.md — Introduction ("Middleware provide a convenient mechanism for inspecting
// and filtering HTTP requests entering your application") and Defining Middleware: the doc's
// EnsureTokenIsValid redirects to `/home` unless the `token` input matches. lifecycle.md — HTTP /
// Console Kernels places the middleware stack before routing.
it('lets a global middleware reject the request with a redirect before the application or allow it deeper', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls:
          - method: afterResolving
            args:
              - Illuminate\Foundation\Http\Kernel
              - - method: pushMiddleware
                  args: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\EnsureTokenIsValid]
          - method: afterResolving
            args:
              - Illuminate\Routing\Router
              - - method: addRoute
                  args:
                    uri: token
                    methods: GET
                    action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Controllers\EchoController
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $tokenless = $this->get('/token');

    $tokenless->assertRedirect('/home');

    expect($tokenless->getContent())->not->toContain('route-output');

    $this->get('/token?token=my-secret-token')->assertOk()->assertContent('route-output');
});

// AT-06 — middleware.md — Middleware and Responses: "this middleware would perform its task after
// the request is handled by the application" — the doc's AfterMiddleware shape; lifecycle.md —
// Finishing Up: the response travels back outward giving the application a chance to modify it.
it('lets a global middleware act on the outgoing response after the request is handled', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls:
          - method: afterResolving
            args:
              - Illuminate\Foundation\Http\Kernel
              - - method: pushMiddleware
                  args: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\AfterMiddleware]
          - method: afterResolving
            args:
              - Illuminate\Routing\Router
              - - method: addRoute
                  args:
                    uri: after
                    methods: GET
                    action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Controllers\EchoController
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $this->get('/after')->assertOk()
        ->assertContent('route-output')
        ->assertHeader('X-After-Task', 'performed');
});

// AT-07 — middleware.md — Defining Middleware note: "All middleware are resolved via the service
// container, so you may type-hint any dependencies you need within a middleware's constructor".
it('injects declared middleware constructor dependencies from the service container', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls:
          - method: afterResolving
            args:
              - Illuminate\Foundation\Http\Kernel
              - - method: pushMiddleware
                  args: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\DependencyMiddleware]
          - method: afterResolving
            args:
              - Illuminate\Routing\Router
              - - method: addRoute
                  args:
                    uri: dependency
                    methods: GET
                    action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Controllers\EchoController
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $this->get('/dependency')->assertOk()
        ->assertHeader('X-Dependency-Report', 'container-resolved');
});

// AT-08 — middleware.md — Excluding Middleware: "The `withoutMiddleware` method can only remove
// route middleware and does not apply to global middleware."
it('keeps global middleware running when a route excludes it with withoutMiddleware', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls:
          - method: afterResolving
            args:
              - Illuminate\Foundation\Http\Kernel
              - - method: pushMiddleware
                  args: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\EnsureTokenIsValid]
          - method: afterResolving
            args:
              - Illuminate\Routing\Router
              - - method: addRoute
                  args:
                    uri: excluded
                    methods: GET
                    action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Controllers\EchoController
                  then:
                    - method: withoutMiddleware
                      args: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\EnsureTokenIsValid]
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $this->get('/excluded')->assertRedirect('/home');

    expect(MiddlewareLog::entries())->toBe([EnsureTokenIsValid::class]);
});

// AT-09 — middleware.md — Middleware Groups: "Sometimes you may want to group several middleware
// under a single key … using the `appendToGroup` method" — the doc's two-member example — and
// "Middleware groups may be assigned to routes … using the same syntax as individual middleware".
it('runs middleware appended to a group when the group is assigned to a route', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls:
          - method: afterResolving
            args:
              - Illuminate\Foundation\Http\Kernel
              - - {method: setMiddlewareGroups, args: [{group-name: []}]}
                - method: appendMiddlewareToGroup
                  args: [group-name, ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\GroupFirst]
                - method: appendMiddlewareToGroup
                  args: [group-name, ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\GroupSecond]
          - method: afterResolving
            args:
              - Illuminate\Routing\Router
              - - method: addRoute
                  args:
                    uri: grouped
                    methods: GET
                    action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Controllers\EchoController
                  then: [{method: middleware, args: [group-name]}]
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $this->get('/grouped')->assertOk();

    expect(MiddlewareLog::entries())->toBe([GroupFirst::class, GroupSecond::class]);
});

// AT-10 — middleware.md — Middleware Groups: the same section's `prependToGroup` form — the doc's
// two-member example — called directly beside `appendToGroup`.
it('runs middleware prepended to a group when the group is assigned to a route', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls:
          - method: afterResolving
            args:
              - Illuminate\Foundation\Http\Kernel
              - - method: setMiddlewareGroups
                  args: [{group-name: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\GroupFirst]}]
                - method: prependMiddlewareToGroup
                  args: [group-name, ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\GroupSecond]
                - method: prependMiddlewareToGroup
                  args: [group-name, ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\GroupPrepended]
          - method: afterResolving
            args:
              - Illuminate\Routing\Router
              - - method: addRoute
                  args:
                    uri: grouped
                    methods: GET
                    action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Controllers\EchoController
                  then: [{method: middleware, args: [group-name]}]
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $this->get('/grouped')->assertOk();

    expect(MiddlewareLog::entries())->toBe([
        GroupPrepended::class,
        GroupSecond::class,
        GroupFirst::class,
    ]);
});

// AT-11 — middleware.md — Manually Managing Laravel's Default Middleware Groups: "you may
// redefine the groups entirely"; the documented default `web` group's StartSession is the member
// lifecycle.md — HTTP / Console Kernels attributes session state to.
it('redefines the default web group entirely with only the declared middleware', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls:
          - method: afterResolving
            args:
              - Illuminate\Foundation\Http\Kernel
              - - method: setMiddlewareGroups
                  args:
                    - web:
                        - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\RecorderA
                        - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\RecorderB
          - method: afterResolving
            args:
              - Illuminate\Routing\Router
              - - method: addRoute
                  args:
                    uri: web-only
                    methods: GET
                    action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Controllers\SessionProbeController
                  then: [{method: middleware, args: [web]}]
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $this->get('/web-only')->assertOk()->assertJsonPath('hasSession', false);

    expect(MiddlewareLog::entries())->toBe([RecorderA::class, RecorderB::class]);
});

// AT-12 — middleware.md — Middleware Aliases: "Middleware aliases allow you to define a short
// alias for a given middleware class" and "you may use the alias when assigning the middleware
// to routes" — the doc's `subscribed` => EnsureUserIsSubscribed example.
it('resolves a declared alias to its middleware when the alias is assigned to a route', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls:
          - method: afterResolving
            args:
              - Illuminate\Foundation\Http\Kernel
              - - method: setMiddlewareAliases
                  args:
                    - subscribed: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\EnsureUserIsSubscribed
          - method: afterResolving
            args:
              - Illuminate\Routing\Router
              - - method: addRoute
                  args:
                    uri: profile
                    methods: GET
                    action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Controllers\EchoController
                  then: [{method: middleware, args: [subscribed]}]
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $this->get('/profile')->assertForbidden();

    expect(MiddlewareLog::entries())->toBe([EnsureUserIsSubscribed::class]);
});

// AT-13 — middleware.md — Sorting Middleware: "you may specify your middleware priority using the
// `priority` method"; routing.md — Route Group Middleware states the overridden baseline:
// "Middleware are executed in the order they are listed in the array".
it('orders route middleware by the declared priority despite the route listing them in the opposite order', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls:
          - method: afterResolving
            args:
              - Illuminate\Foundation\Http\Kernel
              - - method: setMiddlewarePriority
                  args:
                    - - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\High
                      - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\Low
          - method: afterResolving
            args:
              - Illuminate\Routing\Router
              - - method: addRoute
                  args:
                    uri: priority
                    methods: GET
                    action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Controllers\EchoController
                  then:
                    - method: middleware
                      args:
                        - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\Low
                        - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\High
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $this->get('/priority')->assertOk();

    expect(MiddlewareLog::entries())->toBe([High::class, Low::class]);
});

// AT-14 — middleware.md — Sorting Middleware: "The `prependToPriorityList` method inserts the
// given middleware before another middleware"; routing.md — Route Model Binding / Implicit
// Binding supplies the observable: middleware running before the SubstituteBindings anchor sees
// the raw URI segment, not the model.
it('inserts middleware before the SubstituteBindings anchor in the priority list', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls:
          - method: afterResolving
            args:
              - Illuminate\Foundation\Http\Kernel
              - - method: addToMiddlewarePriorityBefore
                  args:
                    - Illuminate\Routing\Middleware\SubstituteBindings
                    - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\PreBindings
          - method: afterResolving
            args:
              - Illuminate\Routing\Router
              - - method: addRoute
                  args:
                    uri: users/{user}
                    methods: GET
                    action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Controllers\ShowUserController
                  then:
                    - method: middleware
                      args:
                        - Illuminate\Routing\Middleware\SubstituteBindings
                        - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\PreBindings
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    Schema::dropIfExists('users');
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('email');
        $table->timestamps();
    });
    User::create(['id' => 1, 'email' => 'ada@example.com']);

    $this->get('/users/1')->assertOk()->assertJsonPath('email', 'ada@example.com');

    expect(PreBindings::$observed)->toBe('1');
});

// AT-15 — middleware.md — Sorting Middleware: "while the `appendToPriorityList` method inserts it
// after another middleware"; routing.md — Route Model Binding / Implicit Binding supplies the
// observable: middleware running after the SubstituteBindings anchor sees the retrieved model.
it('inserts middleware after the SubstituteBindings anchor in the priority list', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls:
          - method: afterResolving
            args:
              - Illuminate\Foundation\Http\Kernel
              - - method: addToMiddlewarePriorityAfter
                  args:
                    - Illuminate\Routing\Middleware\SubstituteBindings
                    - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\PostBindings
          - method: afterResolving
            args:
              - Illuminate\Routing\Router
              - - method: addRoute
                  args:
                    uri: users/{user}
                    methods: GET
                    action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Controllers\ShowUserController
                  then:
                    - method: middleware
                      args:
                        - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\PostBindings
                        - Illuminate\Routing\Middleware\SubstituteBindings
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    Schema::dropIfExists('users');
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('email');
        $table->timestamps();
    });
    User::create(['id' => 1, 'email' => 'ada@example.com']);

    $this->get('/users/1')->assertOk()->assertJsonPath('email', 'ada@example.com');

    expect(PostBindings::$observed)->toBeInstanceOf(User::class)
        ->and(PostBindings::$observed->email)->toBe('ada@example.com');
});

// AT-16 — middleware.md — Terminable Middleware: "the `terminate` method will automatically be
// called after the response is sent to the browser" and "The `terminate` method should receive
// both the request and the response".
it('calls the terminate method of a global middleware after the response is sent', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls:
          - method: afterResolving
            args:
              - Illuminate\Foundation\Http\Kernel
              - - method: pushMiddleware
                  args: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\TerminatingMiddleware]
          - method: afterResolving
            args:
              - Illuminate\Routing\Router
              - - method: addRoute
                  args:
                    uri: terminating
                    methods: GET
                    action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Controllers\EchoController
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $this->get('/terminating')->assertOk()->assertContent('route-output');

    expect(TerminatingMiddleware::$terminated)->not->toBeEmpty()
        ->and(TerminatingMiddleware::$terminateArguments)->toBe([[Request::class, Response::class]]);
});

// AT-17 — middleware.md — Terminable Middleware: "When calling the `terminate` method on your
// middleware, Laravel will resolve a fresh instance of the middleware from the service
// container" — the doc's default, with no singleton registration.
it('resolves a fresh instance from the service container for the terminate call', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls:
          - method: afterResolving
            args:
              - Illuminate\Foundation\Http\Kernel
              - - method: pushMiddleware
                  args: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Middleware\TerminatingMiddleware]
          - method: afterResolving
            args:
              - Illuminate\Routing\Router
              - - method: addRoute
                  args:
                    uri: terminating
                    methods: GET
                    action: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Controllers\EchoController
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $this->get('/terminating')->assertOk();

    expect(TerminatingMiddleware::$handled)->not->toBeEmpty()
        ->and(TerminatingMiddleware::$terminated)->not->toBeEmpty()
        ->and(TerminatingMiddleware::$terminated[0])->not->toBe(TerminatingMiddleware::$handled[0]);
});
