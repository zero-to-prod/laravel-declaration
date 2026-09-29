<?php

declare(strict_types=1);

use Illuminate\Contracts\Translation\Translator;
use ZeroToProd\LaravelDeclaration\App;
use ZeroToProd\LaravelDeclaration\Attributes\Binding;
use ZeroToProd\LaravelDeclaration\Attributes\Hook;
use ZeroToProd\LaravelDeclaration\Attributes\Path;
use ZeroToProd\LaravelDeclaration\Attributes\Setter;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Contracts\Cache;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Contracts\Clock;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Contracts\Pdf;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Contracts\Slugger;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\HookLog;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services\BudgetGuard;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services\DomPdf;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services\RedisCache;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services\ReportBuilder;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services\RequestLog;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services\SlowWarmup;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services\TenantContext;

$manifest = __DIR__.'/../Fixtures/manifest/app.yml';

beforeEach(function (): void {
    HookLog::reset();
});

it('binds abstracts to concrete implementations', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    expect(app(Pdf::class))->toBeInstanceOf(DomPdf::class)
        ->and(app(Pdf::class))->not->toBe(app(Pdf::class))
        ->and(app(Slugger::class))->toBeInstanceOf(Slugger::class)
        ->and(app(Slugger::class)->slug('Tenant Console'))->toBe('tenant-console');
});

it('shares singletons and self-binds null concretes', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    expect(app(TenantContext::class))->toBe(app(TenantContext::class))
        ->and(app(ReportBuilder::class))->toBeInstanceOf(ReportBuilder::class)
        ->and(app(SlowWarmup::class))->toBe(app(SlowWarmup::class));
});

it('shares scoped instances until the scoped instances are forgotten', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $first = app(RequestLog::class);

    expect($first)->toBe(app(RequestLog::class))
        ->and(app(BudgetGuard::class))->toBeInstanceOf(BudgetGuard::class);

    app()->forgetScopedInstances();

    expect(app(RequestLog::class))->not->toBe($first);
});

it('skips the If groups when a binding already exists', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    expect(app(Pdf::class))->toBeInstanceOf(DomPdf::class)
        ->and(app(TenantContext::class))->toBeInstanceOf(TenantContext::class)
        ->and(app(RequestLog::class))->toBeInstanceOf(RequestLog::class)
        ->and(app(Cache::class))->toBeInstanceOf(RedisCache::class)
        ->and(app(Cache::class))->not->toBe(app(Cache::class));
});

it('skips bindIf for a deferred service', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    expect(app('translator'))->toBeInstanceOf(Translator::class);
});

it('binds instances as-is, eagerly makes class-strings and requires .php files', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    expect(app('app.signature'))->toBe('1.0')
        ->and(app(Clock::class))->toBeInstanceOf(Clock::class)
        ->and(app('app.rate_limiter')->capacity)->toBe(60)
        ->and(HookLog::entries())->toContain('Clock');
});

it('aliases an abstract', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    expect(app('context'))->toBe(app(TenantContext::class));
});

it('extends cache.store through the .php extender', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    expect(app('cache.store')->get('app.extension'))->toBe('applied')
        ->and(HookLog::entries())->toContain('store-cache:Illuminate\Cache\Repository');
});

it('rebinds the declared paths under basePath', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    expect(app_path())->toBe(app()->basePath('src'))
        ->and(database_path())->toBe(app()->basePath('database'))
        ->and(lang_path())->toBe(app()->basePath('resources/lang'))
        ->and(public_path())->toBe(app()->basePath('public'))
        ->and(storage_path())->toBe(app()->basePath('storage'))
        ->and(app('path'))->toBe(app_path());
});

it('uses an absolute path value verbatim', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'manifest-').'.yml';
    file_put_contents($file, <<<'YAML'
        app:
          useAppPath: /tmp/declaration-app-path
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(app_path())->toBe('/tmp/declaration-app-path');
});

it('applies the declared locales', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    expect(app()->getLocale())->toBe('fr')
        ->and(app()->getFallbackLocale())->toBe('en')
        ->and(config('app.locale'))->toBe('fr')
        ->and(config('app.fallback_locale'))->toBe('en')
        ->and(app('translator')->getLocale())->toBe('fr')
        ->and(app('translator')->getFallback())->toBe('en')
        ->and(HookLog::entries())->toContain('LocaleUpdated:fr');
});

it('fires the declared lifecycle hooks in order', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    expect(HookLog::entries())->toBe([
        'Clock',
        'LocaleUpdated:fr',
        'registered.php',
        'WarmConnections:booting',
        'booted.php',
    ]);
});

it('fires terminating callbacks through the container', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    app()->terminate();

    expect(HookLog::entries())->toContain('FlushMetrics:now');
});

it('requires a .php reference exactly once across two boots', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $limiter = app('app.rate_limiter');

    $this->refreshApplication();

    expect(app('app.rate_limiter'))->toBe($limiter);
});

it('rejects a .php reference that does not return a Closure', function (): void {
    $php = tempnam(sys_get_temp_dir(), 'reference-').'.php';
    file_put_contents($php, '<?php return 42;');

    $file = tempnam(sys_get_temp_dir(), 'manifest-').'.yml';
    file_put_contents($file, "app:\n  bind:\n    Foo: {$php}\n");

    expect(fn (): bool => $this->withConfig(['laravel-declaration.manifest' => $file]) !== null)
        ->toThrow(LogicException::class, 'must return a Closure, int returned');
});

it('rejects a null list item', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'manifest-').'.yml';
    file_put_contents($file, <<<'YAML'
        app:
          bind:
            - ~
        YAML);

    expect(fn (): bool => $this->withConfig(['laravel-declaration.manifest' => $file]) !== null)
        ->toThrow(LogicException::class, 'The `app.bind` list declares a null item');
});

it('ignores a .php list item under the If keys', function (string $key): void {
    $file = tempnam(sys_get_temp_dir(), 'manifest-').'.yml';
    file_put_contents($file, "app:\n  {$key}:\n    - app/binders/slugger.php\n");

    expect($this->withConfig(['laravel-declaration.manifest' => $file]))->not->toBeNull();
})->with(['bindIf', 'singletonIf', 'scopedIf']);

it('ignores unknown app keys', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'manifest-').'.yml';
    file_put_contents($file, <<<'YAML'
        app:
          singelton:
            Foo: Bar
        YAML);

    expect($this->withConfig(['laravel-declaration.manifest' => $file]))->not->toBeNull()
        ->and(app()->bound('Foo'))->toBeFalse();
});

it('selects binding, path, setter, and hook properties via attributes', function (): void {
    expect(App::selected(Binding::class))->toBe([
        App::bind,
        App::bindIf,
        App::singleton,
        App::singletonIf,
        App::scoped,
        App::scopedIf,
    ])->and(App::selected(Path::class))->toBe([
        App::useAppPath,
        App::useDatabasePath,
        App::useLangPath,
        App::usePublicPath,
        App::useStoragePath,
    ])->and(App::selected(Setter::class))->toBe([
        App::setLocale,
        App::setFallbackLocale,
    ])->and(App::selected(Hook::class))->toBe([
        App::registered,
        App::booting,
        App::booted,
    ]);
});
