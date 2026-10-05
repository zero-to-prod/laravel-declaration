<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Feature;

use Illuminate\Pagination\Paginator;
use ReflectionProperty;

beforeEach(function (): void {
    // Preset statics are process-global (AbstractPaginator lineage) — restore
    // the native defaults so assertions are order-independent.
    Paginator::defaultView('pagination::tailwind');
    Paginator::defaultSimpleView('pagination::simple-tailwind');
});

it('touches nothing when the manifest has no Paginator key', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest('register: []')]);

    expect(defaultView())->toBe('pagination::tailwind')
        ->and(defaultSimpleView())->toBe('pagination::simple-tailwind');
});

it('configures paginator styles and views from manifest', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        Illuminate\Pagination\Paginator:
          useTailwind: ~
          useBootstrapFive: ~
          defaultView: pagination::custom
          defaultSimpleView: pagination::simple-custom
        YAML)]);

    // Explicit views apply after presets — explicit wins.
    expect(defaultView())->toBe('pagination::custom')
        ->and(defaultSimpleView())->toBe('pagination::simple-custom');
});

it('maps useBootstrapThree to the bootstrap-3 views', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        Illuminate\Pagination\Paginator:
          useBootstrapThree: ~
        YAML)]);

    expect(defaultView())->toBe('pagination::bootstrap-3')
        ->and(defaultSimpleView())->toBe('pagination::simple-bootstrap-3');
});

it('maps useBootstrapFour to the bootstrap-4 views', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        Illuminate\Pagination\Paginator:
          useBootstrapFour: ~
        YAML)]);

    expect(defaultView())->toBe('pagination::bootstrap-4')
        ->and(defaultSimpleView())->toBe('pagination::simple-bootstrap-4');
});

it('maps the useBootstrap alias to the bootstrap-4 views', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        Illuminate\Pagination\Paginator:
          useBootstrap: ~
        YAML)]);

    // useBootstrap() delegates to useBootstrapFour() natively (AbstractPaginator.php:630).
    expect(defaultView())->toBe('pagination::bootstrap-4')
        ->and(defaultSimpleView())->toBe('pagination::simple-bootstrap-4');
});

it('applies presets in declaration order — the last truthy preset wins', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        Illuminate\Pagination\Paginator:
          useTailwind: ~
          useBootstrapThree: ~
        YAML)]);

    expect(defaultView())->toBe('pagination::bootstrap-3')
        ->and(defaultSimpleView())->toBe('pagination::simple-bootstrap-3');
});

it('leaves the default views when every preset is omitted', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        Illuminate\Pagination\Paginator: {}
        YAML)]);

    expect(defaultView())->toBe('pagination::tailwind')
        ->and(defaultSimpleView())->toBe('pagination::simple-tailwind');
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
