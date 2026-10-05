<?php

declare(strict_types=1);

use Illuminate\Contracts\Translation\Translator;
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
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\MockClass;

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

it('binds instances as-is, requires .php files and shares a singleton class', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    expect(app('app.signature'))->toBe('1.0')
        ->and(app(Clock::class))->toBeInstanceOf(Clock::class)
        ->and(app(Clock::class))->toBe(app(Clock::class))
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
    $file = $this->manifest(<<<'YAML'
        calls: [{method: useAppPath, args: [/tmp/declaration-app-path]}]
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

it('rejects a .php closure reference that does not return a Closure', function (): void {
    $php = tempnam(sys_get_temp_dir(), 'reference-').'.php';
    file_put_contents($php, '<?php return 42;');

    $file = $this->manifest("calls:\n  - method: registered\n    args:\n      - - {method: booting, args: [{$php}]}\n");

    expect(fn (): bool => $this->withConfig(['laravel-declaration.manifest' => $file]) !== null)
        ->toThrow(LogicException::class, 'must return a Closure, int returned');
});

it('passes a null list item through to Laravel, which rejects it natively', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls: [{method: registered, args: [[{method: bind, args: [null]}]]}]
        YAML);

    expect(fn (): bool => $this->withConfig(['laravel-declaration.manifest' => $file]) !== null)
        ->toThrow(TypeError::class, 'Argument #2 ($concrete) must be of type Closure|string|null');
});

it('fails an unknown application key with the Macroable exception', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls: [{method: singelton, args: [{Foo: Bar}]}]
        YAML);

    expect(fn (): bool => $this->withConfig(['laravel-declaration.manifest' => $file]) !== null)
        ->toThrow(BadMethodCallException::class, 'Method Illuminate\Foundation\Application::singelton does not exist.');
});

it('fails an unknown router key with Laravel\'s own exception', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls: [{method: afterResolving, args: [Illuminate\Routing\Router, [{method: patterns_typo, args: [{id: '[0-9]+'}]}]]}]
        YAML);

    expect(function () use ($file): mixed {
        $this->withConfig(['laravel-declaration.manifest' => $file]);

        return app('router');
    })->toThrow(InvalidArgumentException::class, 'Attribute [patterns_typo] does not exist.');
});

it('applies tag, resolving, afterResolving, and path setters', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls:
          - method: registered
            args:
              - - {method: tag, args: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\MockClass, my_tag]}
                - method: resolving
                  args: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\MockClass, AppTestHelper::resolvingCallback]
                - method: afterResolving
                  args: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\MockClass, AppTestHelper::afterResolvingCallback]
                - {method: useBootstrapPath, args: [bootstrap]}
                - {method: useConfigPath, args: [config]}
                - {method: useEnvironmentPath, args: [env]}
        YAML);

    AppTestHelper::$resolvingCalled = false;
    AppTestHelper::$afterResolvingCalled = false;

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $tagged = app()->tagged('my_tag');
    expect($tagged)->toHaveCount(1);

    app()->make(MockClass::class, ['name' => 'test']);

    expect(AppTestHelper::$resolvingCalled)->toBeTrue()
        ->and(AppTestHelper::$afterResolvingCalled)->toBeTrue()
        ->and(app()->bootstrapPath())->toBe(app()->basePath('bootstrap'))
        ->and(app()->configPath())->toBe(app()->basePath('config'))
        ->and(app()->environmentPath())->toBe(app()->basePath('env'));
});

it('applies contextual when binding', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls:
          - method: registered
            args:
              - - method: when
                  args: {concrete: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\MockClass}
                  then: [{method: needs, args: [$name]}, {method: give, args: [contextual-value]}]
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $instance = app()->make(MockClass::class);
    expect($instance->name)->toBe('contextual-value');
});

class AppTestHelper
{
    public static bool $resolvingCalled = false;

    public static bool $afterResolvingCalled = false;

    public static function resolvingCallback(): void
    {
        self::$resolvingCalled = true;
    }

    public static function afterResolvingCallback(): void
    {
        self::$afterResolvingCalled = true;
    }
}
