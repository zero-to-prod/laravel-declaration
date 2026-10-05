<?php

declare(strict_types=1);

use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Acceptance\UserRepository;

// Acceptance tests for src/View.php — docs/declarative-view-acceptance-test-plan.md.
// Every behavior is sourced from docs/repos/laravel/docs/views.md and
// docs/repos/laravel/docs/packages.md, the systems of record. Fixture views render from
// resources/declared-views and resources/package-views (copied into the Testbench skeleton
// by tests/TestCase.php); fixture composers and creators live in Fixtures/App/View/Acceptance.
//
// Setup dependency (§6 G-1): every documented-behavior manifest below also declares the
// undocumented view.addLocation so its fixture views are findable — the skeleton's default
// path is resources/views, and these fixtures live in resources/declared-views. addLocation
// is itself an undocumented surface (G-1) with no acceptance test; it appears here purely
// as test setup, never as the behavior under test.
//
// AT-03 (closure composer) has no test here: the declared `composer` value is a string the
// Factory resolves as a class composer, so the documented closure form is not declarable —
// plan §6 G-8. The undocumented declared surface addLocation, prependLocation,
// prependNamespace, replaceNamespace, addExtension, flushFinderCache and flushState
// (§6 G-1–G-7) likewise gets no acceptance test.

// AT-01 — views.md — Sharing Data With All Views: "you may need to share data with all
// views that are rendered by your application. You may do so using the View facade's
// share method." — the doc's `View::share('key', 'value')`, spelled as a row because the shared
// key is literally named `key`, the method's own first parameter (the entries form would read it
// as `share(key: 'value')`).
it('makes the declared shared data available to every rendered view', function (): void {
    $file = $this->manifest(<<<'YAML'
        afterResolving:
          Illuminate\View\Factory:
            addLocation:
              - resources/declared-views
            share:
              - key: key
                value: value
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(view('share-first')->render())->toContain('value')
        ->and(view('share-second')->render())->toContain('value');
});

// AT-02 — views.md — View Composers: "the compose method of the App\View\Composers\
// ProfileComposer class will be executed each time the profile view is being rendered" —
// binding `count` derived from the repository like the doc's example. The count varies per
// render (the Factory builds a fresh composer per render), so the second render showing the
// next count proves compose ran again rather than replaying memoized data.
it('runs the declared composer each time its view is rendered', function (): void {
    $file = $this->manifest(<<<'YAML'
        afterResolving:
          Illuminate\View\Factory:
            addLocation:
              - resources/declared-views
            composer:
              profile: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Acceptance\ProfileComposer
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $first = view('profile')->render();
    $second = view('profile')->render();

    expect((int) $first)->toBeGreaterThan(0)
        ->and($second)->toBe((string) ((int) $first + 1));
});

// AT-04 — views.md — View Composers: "all view composers are resolved via the service
// container, so you may type-hint any dependencies you need within a composer's
// constructor" — the same ProfileComposer AT-02 exercises. The repository is bound in the
// container to a fixed count, so the output shows it only when the container injected the
// composer's constructor dependency.
it('resolves the declared composer through the service container', function (): void {
    $file = $this->manifest(<<<'YAML'
        afterResolving:
          Illuminate\View\Factory:
            addLocation:
              - resources/declared-views
            composer:
              profile: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Acceptance\ProfileComposer
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $this->app->bind(UserRepository::class, fn (): UserRepository => new UserRepository(42));

    expect(view('profile')->render())->toBe('42');
});

// AT-05 — views.md — Attaching a Composer to Multiple Views: "passing an array of views as
// the first argument to the composer method" — the row form `{views: [...], callback}` is the
// doc's `['profile', 'dashboard']` first argument spelled by parameter name.
it('runs the composer declared for multiple views on each attached view', function (): void {
    $file = $this->manifest(<<<'YAML'
        afterResolving:
          Illuminate\View\Factory:
            addLocation:
              - resources/declared-views
            composer:
              - views: [profile, dashboard]
                callback: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Acceptance\MultiComposer
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(view('profile')->render())->toContain('multi')
        ->and(view('dashboard')->render())->toContain('multi');
});

// AT-06 — views.md — Attaching a Composer to Multiple Views: "The composer method also
// accepts the * character as a wildcard, allowing you to attach a composer to all views."
it('attaches the declared wildcard composer to all views', function (): void {
    $file = $this->manifest(<<<'YAML'
        afterResolving:
          Illuminate\View\Factory:
            addLocation:
              - resources/declared-views
            composer:
              '*': ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Acceptance\WildcardComposer
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(view('wildcard-first')->render())->toContain('wildcard')
        ->and(view('wildcard-second')->render())->toContain('wildcard');
});

// AT-07 — views.md — View Creators: creators "are executed immediately after the view is
// instantiated instead of waiting until the view is about to render" — the render-time
// composer observes the creator's data already bound, and the output shows it.
it('runs the creator before render-time composing', function (): void {
    $file = $this->manifest(<<<'YAML'
        afterResolving:
          Illuminate\View\Factory:
            addLocation:
              - resources/declared-views/creator
            creator:
              profile: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Acceptance\ProfileCreator
            composer:
              profile: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View\Acceptance\CreatorOrderObserver
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(view('profile')->render())->toBe('created creator-first');
});

// AT-08 — packages.md — Views: "Package views are referenced using the package::view
// syntax convention ... you may load the dashboard view from the courier package".
it('loads a namespaced view through package::view syntax', function (): void {
    $file = $this->manifest(<<<'YAML'
        afterResolving:
          Illuminate\View\Factory:
            addNamespace:
              courier: resources/package-views/courier
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(view('courier::dashboard')->render())->toBe('package-dashboard');
});

// AT-09 — packages.md — Overriding Package Views: Laravel "will first check if a custom
// version of the view has been placed in the resources/views/vendor/courier directory ...
// Then ... Laravel will search the package view directory" — declared as two hints,
// vendor override first.
it('prefers the vendor override directory over the package directory', function (): void {
    $file = $this->manifest(<<<'YAML'
        afterResolving:
          Illuminate\View\Factory:
            addNamespace:
              courier:
                - resources/views/vendor/courier
                - resources/package-views/courier
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(view('courier::dashboard')->render())->toBe('vendor-dashboard');
});
