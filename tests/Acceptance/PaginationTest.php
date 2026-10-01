<?php

declare(strict_types=1);

use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

// Acceptance tests for the manifest `pagination:` block — docs/declarative-pagination-acceptance-test-plan.md.
// Every behavior is sourced from docs/repos/laravel/docs/pagination.md (plus packages.md for the
// `pagination::` namespace), the systems of record. Two preset keys (`useBootstrap`,
// `useBootstrapThree`) are documented by README § Pagination but absent from the vendored docs —
// for those, the framework source (vendor/laravel/framework/src/Illuminate/Pagination/AbstractPaginator.php,
// v13.33.0) is the only source, as each such test says so explicitly. The vendored docs name the
// `defaultView`/`defaultSimpleView` methods but not their per-paginator consumers — AT-04's
// consumer mapping is framework-verified per its citation.
//
// Plan §6 (documented but non-declarable) has no acceptance test here: the `vendor:publish
// --tag=laravel-pagination` workflow, `onEachSide`/`toJson` instance behavior, the shipped
// `pagination::semantic-ui` view (no preset method in v13.33.0), the remaining request-scoped
// `AbstractPaginator` statics, and per-instance additional-data/JSON URL plumbing.
//
// Harness prerequisite: the preset state lives in `public static` properties on the
// `AbstractPaginator` lineage and is therefore process-global — `Paginator`,
// `LengthAwarePaginator`, and (indirectly) `CursorPaginator` all read the same statics. Every
// test resets both statics to the native defaults before its Given, so assertions are
// order-independent, and asserts via ReflectionProperty on `Paginator::class` (the statics are
// inherited, not redeclared).

beforeEach(function (): void {
    Paginator::defaultView('pagination::tailwind');
    Paginator::defaultSimpleView('pagination::simple-tailwind');
});

// AT-01 — pagination.md — Customizing the Pagination View: "By default, the views rendered to
// display the pagination links are compatible with the Tailwind CSS framework." and, of the
// published views, "The tailwind.blade.php file within this directory corresponds to the default
// pagination view."
it('defaults the pagination views to the Tailwind pair', function (): void {
    expect(defaultView())->toBe('pagination::tailwind')
        ->and(defaultSimpleView())->toBe('pagination::simple-tailwind');
});

// AT-02 — pagination.md — Customizing the Pagination View: "When calling the `links` method on a
// paginator instance, you may pass the view name as the first argument to the method."
it('overrides the default view for a single instance via the links argument', function (): void {
    $file = $this->manifest(<<<'YAML'
        pagination:
          defaultView: pagination::custom
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $first = new LengthAwarePaginator(['a', 'b'], 2, 1);
    $second = new LengthAwarePaginator(['a', 'b'], 2, 1);

    // the per-instance argument wins for that instance…
    expect($first->links('pagination::other')->toHtml())->toContain('other-view:2');

    // …while the declared default remains the fallback for the other instance
    expect($second->links()->toHtml())->toContain('custom-default:2');
});

// AT-03 — pagination.md — Customizing the Pagination View: the `links` call accepts additional
// data — `{{ $paginator->links('view.name', ['foo' => 'bar']) }}`.
it('forwards additional data passed to links to the view', function (): void {
    $paginator = new LengthAwarePaginator(['a', 'b'], 2, 1);

    expect($paginator->links('pagination::other', ['foo' => 'bar'])->toHtml())
        ->toContain('other-view:2-foo:bar');
});

// AT-04 — pagination.md — Displaying Pagination Results: "calling the `paginate` method, you
// will receive an instance of Illuminate\Pagination\LengthAwarePaginator, while calling the
// `simplePaginate` method returns an instance of Illuminate\Pagination\Paginator. And, finally,
// calling the `cursorPaginate` method returns an instance of Illuminate\Pagination\CursorPaginator."
// The vendored docs name the `defaultView` and `defaultSimpleView` methods but not their
// consumers; the consumer mapping is framework-verified (each paginator renders
// `$view ?: <its default static>` — LengthAwarePaginator.php:102, Paginator.php:119,
// CursorPaginator.php:100, which reads `Paginator::$defaultSimpleView`).
it('feeds length-aware paginators defaultView and simple & cursor paginators defaultSimpleView', function (): void {
    $file = $this->manifest(<<<'YAML'
        pagination:
          defaultView: pagination::custom
          defaultSimpleView: pagination::simple-custom
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(new LengthAwarePaginator(['a', 'b'], 2, 1)->links()->toHtml())
        ->toContain('custom-default:2')
        ->and(new Paginator(['a', 'b'], 2)->links()->toHtml())
        ->toContain('custom-simple-default:2')
        ->and(new CursorPaginator(['a', 'b'], 2)->links()->toHtml())
        ->toContain('custom-simple-default:2');
});

// AT-05 — pagination.md — Customizing the Pagination View: "the default views are the Tailwind
// views"; README § Pagination: the key is a "native Illuminate\Pagination\Paginator static
// preset" dispatching `Paginator::useTailwind()` (AbstractPaginator.php:617-621).
it('maps useTailwind to both Tailwind default views', function (): void {
    $file = $this->manifest(<<<'YAML'
        pagination:
          useTailwind: true
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    // the statics were reset to non-default values first, so the dispatch is observable
    expect(defaultView())->toBe('pagination::tailwind')
        ->and(defaultSimpleView())->toBe('pagination::simple-tailwind');
});

// AT-06 — README § Pagination: the key `useBootstrap` is "alias for useBootstrapFour()". The
// vendored pagination.md does not document `useBootstrap`; the framework source is the only
// source: the method body is the single statement `static::useBootstrapFour();`
// (AbstractPaginator.php:628-631).
it('maps the useBootstrap alias to the Bootstrap 4 views', function (): void {
    $file = $this->manifest(<<<'YAML'
        pagination:
          useBootstrap: true
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(defaultView())->toBe('pagination::bootstrap-4')
        ->and(defaultSimpleView())->toBe('pagination::simple-bootstrap-4');
});

// AT-07 — README § Pagination: the key `useBootstrapThree` is a native preset. The vendored
// pagination.md documents only `useBootstrapFour`/`useBootstrapFive`; the framework source is the
// only source: the method sets `defaultView('pagination::bootstrap-3')` +
// `defaultSimpleView('pagination::simple-bootstrap-3')` (AbstractPaginator.php:638-642).
it('maps useBootstrapThree to the Bootstrap 3 views', function (): void {
    $file = $this->manifest(<<<'YAML'
        pagination:
          useBootstrapThree: true
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(defaultView())->toBe('pagination::bootstrap-3')
        ->and(defaultSimpleView())->toBe('pagination::simple-bootstrap-3');
});

// AT-08 — pagination.md — Using Bootstrap: "Laravel includes pagination views built using
// Bootstrap CSS. To use these views instead of the default Tailwind views, you may call the
// paginator's `useBootstrapFour` or `useBootstrapFive` methods within the `boot` method of your
// App\Providers\AppServiceProvider class."
it('maps useBootstrapFour to the built-in Bootstrap 4 views', function (): void {
    $file = $this->manifest(<<<'YAML'
        pagination:
          useBootstrapFour: true
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(defaultView())->toBe('pagination::bootstrap-4')
        ->and(defaultSimpleView())->toBe('pagination::simple-bootstrap-4');
});

// AT-09 — pagination.md — Using Bootstrap: same clause as AT-08 ("you may call the paginator's
// `useBootstrapFour` or `useBootstrapFive` methods").
it('maps useBootstrapFive to the built-in Bootstrap 5 views', function (): void {
    $file = $this->manifest(<<<'YAML'
        pagination:
          useBootstrapFive: true
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(defaultView())->toBe('pagination::bootstrap-5')
        ->and(defaultSimpleView())->toBe('pagination::simple-bootstrap-5');
});

// AT-10 — README § Pagination: "Presets apply in the order above and each overwrites both default
// views, so the last truthy preset wins."
it('applies presets in declaration order — the last truthy preset wins', function (): void {
    $file = $this->manifest(<<<'YAML'
        pagination:
          useTailwind: true
          useBootstrapThree: true
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    // the later preset overwrote both views set by the earlier one; no conflict error
    expect(defaultView())->toBe('pagination::bootstrap-3')
        ->and(defaultSimpleView())->toBe('pagination::simple-bootstrap-3');
});

// AT-11 — README § Pagination: "each overwrites both default views, so the last truthy preset
// wins" (AbstractPaginator.php:649-664 — each preset is a plain overwrite of both statics).
it('lets the last truthy preset win within the Bootstrap family', function (): void {
    $file = $this->manifest(<<<'YAML'
        pagination:
          useBootstrapFour: true
          useBootstrapFive: true
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(defaultView())->toBe('pagination::bootstrap-5')
        ->and(defaultSimpleView())->toBe('pagination::simple-bootstrap-5');
});

// AT-12 — README § Pagination: "Omitted or `false` presets are never applied."
it('never applies presets declared false', function (): void {
    $file = $this->manifest(<<<'YAML'
        pagination:
          useTailwind: false
          useBootstrap: false
          useBootstrapThree: false
          useBootstrapFour: false
          useBootstrapFive: false
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    // no native preset call was dispatched — the native Tailwind defaults stand
    expect(defaultView())->toBe('pagination::tailwind')
        ->and(defaultSimpleView())->toBe('pagination::simple-tailwind');
});

// AT-13 — README § Pagination: the presets are declared "in the `pagination` object"; a manifest
// that does not declare the block has nothing to apply.
it('touches nothing when the manifest has no pagination block', function (): void {
    $file = $this->manifest(<<<'YAML'
        app: {}
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(defaultView())->toBe('pagination::tailwind')
        ->and(defaultSimpleView())->toBe('pagination::simple-tailwind');
});

// AT-14 — pagination.md — Customizing the Pagination View: "If you would like to designate a
// different file as the default pagination view, you may invoke the paginator's `defaultView` and
// `defaultSimpleView` methods within the `boot` method of your
// App\Providers\AppServiceProvider class" — `Paginator::defaultView('view-name');`
// (AbstractPaginator.php:596-599 — the native setter writes only its own static).
it('designates the default pagination view via defaultView alone', function (): void {
    $file = $this->manifest(<<<'YAML'
        pagination:
          defaultView: pagination::custom
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(defaultView())->toBe('pagination::custom')
        ->and(defaultSimpleView())->toBe('pagination::simple-tailwind');
});

// AT-15 — pagination.md — Customizing the Pagination View: same clause as AT-14 —
// `Paginator::defaultSimpleView('view-name');` (AbstractPaginator.php:607-610).
it('designates the default simple pagination view via defaultSimpleView alone', function (): void {
    $file = $this->manifest(<<<'YAML'
        pagination:
          defaultSimpleView: pagination::simple-custom
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(defaultSimpleView())->toBe('pagination::simple-custom')
        ->and(defaultView())->toBe('pagination::tailwind');
});

// AT-16 — README § Pagination: "`defaultView` / `defaultSimpleView` apply after the presets and
// override them."
it('applies the explicit views after the presets, overriding them', function (): void {
    $file = $this->manifest(<<<'YAML'
        pagination:
          useBootstrapFour: true
          defaultView: pagination::custom
          defaultSimpleView: pagination::simple-custom
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    // the explicit keys overwrote the Bootstrap 4 views the preset had set, not the other way around
    expect(defaultView())->toBe('pagination::custom')
        ->and(defaultSimpleView())->toBe('pagination::simple-custom');
});

// AT-17 — README § Pagination: each key maps to exactly one native call
// (`defaultSimpleView:` → `Paginator::defaultSimpleView($view)`), and the native setters each
// write only their own static (AbstractPaginator.php:596-599,607-610).
it('keeps the explicit keys independent — defaultSimpleView does not disturb a preset defaultView', function (): void {
    $file = $this->manifest(<<<'YAML'
        pagination:
          useBootstrapFive: true
          defaultSimpleView: pagination::simple-custom
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    // overriding one default view does not reset the other
    expect(defaultView())->toBe('pagination::bootstrap-5')
        ->and(defaultSimpleView())->toBe('pagination::simple-custom');
});

/** Read the inherited `AbstractPaginator` statics through `Paginator`. */
function defaultView(): string
{
    return (string) new ReflectionProperty(Paginator::class, 'defaultView')->getValue();
}

/** @see defaultView() */
function defaultSimpleView(): string
{
    return (string) new ReflectionProperty(Paginator::class, 'defaultSimpleView')->getValue();
}
