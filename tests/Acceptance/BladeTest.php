<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Blade\Components\Alert;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Blade\Money;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Blade\PlainStringable;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Blade\VersionedDatetimeDirective;

// Acceptance tests for src/Blade.php — docs/declarative-blade-acceptance-test-plan.md.
// Every behavior is sourced from docs/repos/laravel/docs/blade.md, the system of record.
// `components` (G-1) and `anonymousComponentNamespace` (G-2) are undocumented declared
// surface with no doc-backed test (§8), so they get none here.

// Rendering a view registers it under a compiled path keyed by the view's path, so the
// shared Testbench skeleton would leak compiled views between tests and runs.
beforeEach(function (): void {
    (new Filesystem)->cleanDirectory(storage_path('framework/views'));
});

// AT-01 — blade.md — Extending Blade: "When the Blade compiler encounters the custom
// directive, it will call the provided callback with the expression that the directive
// contains."
it('compiles the declared directive through the callback with the directive expression', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls:
          - {method: afterResolving, args: [Illuminate\View\Factory, [{method: addLocation, args: [resources/declared-views]}]]}
          - method: afterResolving
            args:
              - Illuminate\View\Compilers\BladeCompiler
              - - method: directive
                  args: [datetime, ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Blade\DatetimeDirective::compile]
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $output = view('blade.datetime', ['var' => new DateTime('2020-01-01 12:00')])->render();

    expect($output)->toBe('01/01/2020 12:00');
});

// AT-02 — blade.md — Extending Blade: "After updating the logic of a Blade directive, you
// will need to delete all of the cached Blade views. The cached Blade views may be removed
// using the view:clear Artisan command."
it('keeps old directive logic in the compiled view cache until view:clear', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls:
          - {method: afterResolving, args: [Illuminate\View\Factory, [{method: addLocation, args: [resources/declared-views]}]]}
          - method: afterResolving
            args:
              - Illuminate\View\Compilers\BladeCompiler
              - - method: directive
                  args:
                    - versioned
                    - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Blade\VersionedDatetimeDirective::compile
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    VersionedDatetimeDirective::$format = 'm/d/Y H:i';

    $data = ['var' => new DateTimeImmutable('2020-01-01 12:00')];

    expect(view('blade.directive-cache', $data)->render())->toBe('01/01/2020 12:00');

    VersionedDatetimeDirective::$format = 'H:i:s'; // the updated directive logic

    expect(view('blade.directive-cache', $data)->render())->toBe('01/01/2020 12:00');

    $this->artisan('view:clear')->assertSuccessful();

    expect(view('blade.directive-cache', $data)->render())->toBe('12:00:00');
});

// AT-03 — blade.md — Custom Echo Handlers: "If you attempt to 'echo' an object using
// Blade, the object's __toString method will be invoked." The manifest declares a
// `stringable` entry for an unrelated type, and none of them covers PlainStringable.
it('echoes objects through __toString when no stringable handler covers the type', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls:
          - {method: afterResolving, args: [Illuminate\View\Factory, [{method: addLocation, args: [resources/declared-views]}]]}
          - method: afterResolving
            args:
              - Illuminate\View\Compilers\BladeCompiler
              - - method: stringable
                  args:
                    - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Blade\Money
                    - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Blade\MoneyEchoHandler::render
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(view('blade.echo', ['obj' => new PlainStringable])->render())->toBe('plain-string');
});

// AT-04 — blade.md — Custom Echo Handlers: "Blade allows you to register a custom echo
// handler for that particular type of object... The stringable method accepts a closure.
// This closure should type-hint the type of object that it is responsible for rendering."
it('renders the stringable handler instead of __toString for the declared class', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls:
          - {method: afterResolving, args: [Illuminate\View\Factory, [{method: addLocation, args: [resources/declared-views]}]]}
          - method: afterResolving
            args:
              - Illuminate\View\Compilers\BladeCompiler
              - - method: stringable
                  args:
                    - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Blade\Money
                    - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Blade\MoneyEchoHandler::render
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $money = new Money(123450);

    expect(view('blade.stringable', ['money' => $money])->render())->toBe('Cost: £1,234.50')
        ->and((string) $money)->toBe('raw:123450');
});

// AT-05 — blade.md — Custom If Statements: "Blade provides a Blade::if method which allows
// you to quickly define custom conditional directives using closures" — only the first
// matching @disk/@elsedisk branch, else @else, renders.
it('renders only the branch whose condition matches the declared if handler', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls:
          - {method: afterResolving, args: [Illuminate\View\Factory, [{method: addLocation, args: [resources/declared-views]}]]}
          - method: afterResolving
            args:
              - Illuminate\View\Compilers\BladeCompiler
              - - method: if
                  args: [disk, ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Blade\DiskCondition::check]
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file, 'filesystems.default' => 'local']);

    expect(trim(view('blade.disk')->render()))->toBe('local');

    $this->withConfig(['laravel-declaration.manifest' => $file, 'filesystems.default' => 's3']);

    expect(trim(view('blade.disk')->render()))->toBe('s3');

    $this->withConfig(['laravel-declaration.manifest' => $file, 'filesystems.default' => 'ftp']);

    expect(trim(view('blade.disk')->render()))->toBe('other');
});

// AT-06 — blade.md — Custom If Statements: the disk conditional is also usable as
// "@unlessdisk('local') ... @enddisk" — the negated variant: the body renders the
// negation of the handler's result (skipped when the handler returns true, rendered
// when it returns false).
it('renders the unless-variant body only when the condition handler returns false', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls:
          - {method: afterResolving, args: [Illuminate\View\Factory, [{method: addLocation, args: [resources/declared-views]}]]}
          - method: afterResolving
            args:
              - Illuminate\View\Compilers\BladeCompiler
              - - method: if
                  args: [disk, ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Blade\DiskCondition::check]
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file, 'filesystems.default' => 'local']);

    expect(view('blade.unless-disk')->render())->toBeEmpty();

    $this->withConfig(['laravel-declaration.manifest' => $file, 'filesystems.default' => 's3']);

    expect(trim(view('blade.unless-disk')->render()))->toBe('not-local');
});

// AT-07 — blade.md — HTML Entity Encoding: "By default, Blade (and the Laravel e
// function) will double encode HTML entities."
it('double-encodes HTML entities by default', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls:
          - {method: afterResolving, args: [Illuminate\View\Factory, [{method: addLocation, args: [resources/declared-views]}]]}
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(view('blade.entity', ['value' => '&amp;'])->render())->toBe('&amp;amp;');
});

// AT-08 — blade.md — HTML Entity Encoding: "If you would like to disable double encoding,
// call the Blade::withoutDoubleEncoding method from the boot method of your
// AppServiceProvider."
it('encodes HTML entities once when withoutDoubleEncoding is declared', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls:
          - {method: afterResolving, args: [Illuminate\View\Factory, [{method: addLocation, args: [resources/declared-views]}]]}
          - {method: afterResolving, args: [Illuminate\View\Compilers\BladeCompiler, [{method: withoutDoubleEncoding}]]}
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(view('blade.entity', ['value' => '&amp;'])->render())->toBe('&amp;');
});

// AT-09 — blade.md — Manually Registering Package Components: "Blade::component('package-alert',
// Alert::class)" — the tag alias renders the declared component class, which does not live
// in the auto-discovered app/View/Components directory.
it('renders the component class under its declared tag alias', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls:
          - {method: afterResolving, args: [Illuminate\View\Factory, [{method: addLocation, args: [resources/declared-views]}]]}
          - method: afterResolving
            args:
              - Illuminate\View\Compilers\BladeCompiler
              - - method: component
                  args: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Blade\Components\Alert, package-alert]
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(view('blade.component-alert')->render())->toBe('<span class="alert-info"></span>')
        ->and(Alert::class)->not->toContain('App\\View\\Components');
});

// AT-10 — blade.md — Anonymous Component Paths: "When component paths are registered
// without a specified prefix ... they may be rendered in your Blade components without a
// corresponding prefix as well." Registering the extra path must not break default
// discovery from resources/views/components, so <x-badge/> is resolved alongside <x-panel />.
it('resolves an anonymous component from the declared unprefixed path', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls:
          - {method: afterResolving, args: [Illuminate\View\Factory, [{method: addLocation, args: [resources/declared-views]}]]}
          - method: afterResolving
            args:
              - Illuminate\View\Compilers\BladeCompiler
              - [{method: anonymousComponentPath, args: {path: resources/acceptance-components}}]
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(view('blade.anonymous-panel')->render())->toBe('<div class="panel"></div><span class="badge"></span>');
});

// AT-11 — blade.md — Anonymous Component Paths: "When a prefix is provided, components
// within that 'namespace' may be rendered by prefixing the component's namespace to the
// component name when the component is rendered: <x-dashboard::panel />".
it('resolves an anonymous component from the declared prefixed path', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls:
          - {method: afterResolving, args: [Illuminate\View\Factory, [{method: addLocation, args: [resources/declared-views]}]]}
          - method: afterResolving
            args:
              - Illuminate\View\Compilers\BladeCompiler
              - [{method: anonymousComponentPath, args: {path: resources/acceptance-components, prefix: dashboard}}]
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(view('blade.anonymous-prefixed')->render())->toBe('<div class="panel"></div>');
});
