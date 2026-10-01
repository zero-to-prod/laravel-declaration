<?php

declare(strict_types=1);

// Acceptance tests for the manifest `config:` block — docs/declarative-configuration-acceptance-test-plan.md.
// Every behavior is sourced from docs/repos/laravel/docs/configuration.md (plus packages.md,
// lifecycle.md and providers.md for the lifecycle interplay), the systems of record, and the
// README § Config declared surface. docs/declarative-configuration.md is cited only for the
// source-derived supplements the plan flags per test, never as the sole source.
//
// Setup dependency (AT-13, AT-14): `config:cache` bootstraps a fresh application from the
// skeleton's bootstrap/app.php, whose providers come from bootstrap/cache/testbench.yaml —
// the tests write LaravelDeclarationProvider into it (and the declared values into a
// repo-root manifest/app.yml, the default `laravel-declaration.manifest` path) and purge
// every artifact in `finally`. The cache-reading app is bootstrapped with the framework's
// LoadConfiguration, the bootstrapper that consults the cached file.
//
// Plan §5 gaps (G-1 scalar under a file key, G-2 scalar `config:` block, G-3 list under a
// file key, G-4 invisibility to earlier registers' register()) have no doc-backed behavior
// and no acceptance test here.

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use ZeroToProd\LaravelDeclaration\LaravelDeclarationProvider;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\ViewWarmProvider;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\ConfigSpyProvider;

use function Orchestra\Testbench\default_skeleton_path;

// AT-01 — configuration.md — Accessing Configuration Values: "you may easily access your
// configuration values using the `Config` facade or global `config` function from anywhere
// in your application … using 'dot' syntax, which includes the name of the file and option".
it('serves a declared value through dot syntax from a route and the facade', function (): void {
    $file = $this->manifest(<<<'YAML'
        config:
          app:
            name: Tenant Console
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    // the global `config` function, called from a route — anywhere in the application
    $this->app->make(Router::class)->get('/acceptance/config-name', fn (): string => config('app.name'));

    $this->get('/acceptance/config-name')
        ->assertOk()
        ->assertSee('Tenant Console');

    // the `Config` facade, same value — first YAML level is the file, second the option
    expect(Config::get('app.name'))->toBe('Tenant Console');
});

// AT-02 — configuration.md — Accessing Configuration Values: "A default value may also be
// specified and will be returned if the configuration option does not exist."
it('returns the default when the option does not exist', function (): void {
    $file = $this->manifest(<<<'YAML'
        config:
          app:
            name: Tenant Console
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(config('app.nickname', 'Fallback'))->toBe('Fallback');
});

// AT-03 — configuration.md — Accessing Configuration Values: typed retrieval methods,
// "If the retrieved configuration value does not match the expected type, an exception
// will be thrown" — the Repository throws InvalidArgumentException.
it('supports typed retrieval and throws on a type mismatch', function (): void {
    $file = $this->manifest(<<<'YAML'
        config:
          app:
            name: Tenant Console
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(Config::string('app.name'))->toBe('Tenant Console')
        ->and(fn (): int => Config::integer('app.name'))->toThrow(InvalidArgumentException::class);
});

// AT-04 — README § Config: "Your existing configurations are merged. The `manifest` values
// win over existing values." configuration.md documents `config(['app.timezone' => …])` as
// the runtime set the manifest block maps onto; packages.md's `mergeConfigFrom` has the
// opposite precedence (existing values win), which is why the manifest can win at all.
it('replaces an existing value with the declared one', function (): void {
    $file = $this->manifest(<<<'YAML'
        config:
          app:
            name: Tenant Console
        YAML);

    // 'Legacy' sits in the repository where the file's `env('APP_NAME')` would have landed
    $this->withConfig(['app.name' => 'Legacy', 'laravel-declaration.manifest' => $file]);

    expect(config('app.name'))->toBe('Tenant Console');
});

// AT-05 — README § Config: "`name: Tenant Console  # config('app.name'); other app.* keys
// survive`" — the set addresses one node and leaves the rest of the file's map intact. The
// sibling value is the config file's own literal ('UTC' — ./config/app.php's declared
// 'timezone'), untouched by the declaration.
it('keeps the siblings of a declared key', function (): void {
    $file = $this->manifest(<<<'YAML'
        config:
          app:
            name: Tenant Console
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(config('app.timezone'))->toBe('UTC')
        ->and(config('app.name'))->toBe('Tenant Console')
        // supplement (declarative-configuration.md §1.2): `config('app')` is the whole
        // app array including both keys
        ->and(config('app'))->name->toBe('Tenant Console')
        ->and(config('app'))->timezone->toBe('UTC');
});

// AT-06 — README § Config: "`stores.redis.connection: cache  # one nested key; the rest of
// stores.redis survives`" — a key that is itself a dot-path sets one nested node.
it('sets a dot-path key as one nested node, keeping the rest of the node', function (): void {
    $file = $this->manifest(<<<'YAML'
        config:
          cache:
            stores.redis.connection: acceptance-cache
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(config('cache.stores.redis.connection'))->toBe('acceptance-cache')
        ->and(config('cache.stores.redis.driver'))->toBe('redis')
        ->and(config('cache.stores.redis.lock_connection'))->toBe('default');
});

// AT-07 — README § Config: "`sentinel:  # a key no file declares: gained whole`" — a file
// key no config file declares starts from nothing, with no extra registration mechanism.
it('gains a file key no config file declares', function (): void {
    $file = $this->manifest(<<<'YAML'
        config:
          sentinel:
            meters: true
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(config('sentinel.meters'))->toBeTrue()
        ->and(Config::get('sentinel'))->toBe(['meters' => true]);
});

// AT-08 — declarative-configuration.md §2.4, §2.6 (source-derived): "`Config::set()`
// semantics, by design. A key whose value is a map replaces that node whole; to change one
// nested key, declare its dot-path" — the rejected alternative was recursive merging.
it('replaces a map value wholesale instead of merging it recursively', function (): void {
    $file = $this->manifest(<<<'YAML'
        config:
          database:
            redis:
              host: declared-host
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(config('database.redis'))->toBe(['host' => 'declared-host'])
        // the file's own keys inside the replaced node are gone — declare their dot-paths
        // separately to keep them
        ->and(config('database.redis.client'))->toBeNull()
        ->and(config('database.redis.options'))->toBeNull();
});

// AT-09 — lifecycle.md — Service Providers: "once all of the providers have been registered,
// the `boot` method will be called on each provider. This is so service providers may depend
// on every container binding being registered … by the time their `boot` method is executed".
// ViewWarmProvider registers BEFORE LaravelDeclarationProvider (tests/TestCase.php) and reads
// config('app.name') in boot() — HookLog records whatever boot() saw, whatever the provider
// order.
it('makes the declared values visible to the boot of providers registered earlier', function (): void {
    $file = $this->manifest(<<<'YAML'
        config:
          app:
            name: Tenant Console
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    try {
        expect(ViewWarmProvider::$bootSeenAppName)->toBe('Tenant Console');
    } finally {
        ViewWarmProvider::$bootSeenAppName = null;
    }
});

// AT-10 — lifecycle.md — Service Providers (register-all-then-boot-all) + declarative-configuration.md
// §1.1, §1.3 (source-derived): the set runs in the declaration provider's register(), and the
// manifest's `providers` register from its boot() — so a declared provider's register() runs
// after the set and sees the declared values.
it('makes the declared values visible to a declared provider register and boot', function (): void {
    $file = $this->manifest(<<<'YAML'
        config:
          app:
            name: Tenant Console
        providers:
          - class: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\ConfigSpyProvider
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    try {
        expect(ConfigSpyProvider::$seen)->toBe([
            'register' => 'Tenant Console',
            'boot' => 'Tenant Console',
        ]);
    } finally {
        ConfigSpyProvider::$seen = [];
    }
});

// AT-11 — configuration.md — Determining the Current Environment: "The current application
// environment is determined via the `APP_ENV` variable from your `.env` file" + §1.1 of the
// design doc (source-derived): LoadConfiguration consumes `app.env` and `app.timezone`
// before any provider registers, so declaring them changes `config()` only — the environment
// binding and the PHP timezone keep the values they were bootstrapped with. The effects are
// captured before the manifest-bearing application is created and must be unchanged after.
it('changes only the repository when the bootstrap-consumed keys are declared', function (): void {
    $environmentBefore = $this->app->environment();
    $timezoneBefore = date_default_timezone_get();

    $file = $this->manifest(<<<'YAML'
        config:
          app:
            env: staging
            timezone: Antarctica/Troll
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect($this->app->environment())->toBe($environmentBefore)
        ->and(config('app.env'))->toBe('staging')
        ->and(config('app.timezone'))->toBe('Antarctica/Troll')
        ->and(date_default_timezone_get())->toBe($timezoneBefore);
});

// AT-12 — configuration.md — Retrieving Environment Configuration: `.env` values are "read
// by the configuration files within the `config` directory using Laravel's `env` function";
// README § Config: manifest values win. declarative-configuration.md §2.6 (source-derived):
// `env()` called directly still returns the raw value. The first application proves the
// file's env('APP_NAME') actually sources the process variable; the second adds the manifest
// block, whose value wins in config() while env() stays raw.
it('wins over the env-sourced value while env keeps returning the raw one', function (): void {
    putenv('APP_NAME=Legacy');
    $_ENV['APP_NAME'] = 'Legacy';
    $_SERVER['APP_NAME'] = 'Legacy';

    try {
        // without a config block, config/app.php's env('APP_NAME') reaches the repository
        // (a manifest path no file provides, so the declaration provider loads nothing)
        $missing = tempnam(sys_get_temp_dir(), 'missing-');
        unlink($missing);

        $this->withConfig(['laravel-declaration.manifest' => $missing]);

        expect(config('app.name'))->toBe('Legacy');

        $file = $this->manifest(<<<'YAML'
            config:
              app:
                name: Tenant Console
            YAML);

        $this->withConfig(['laravel-declaration.manifest' => $file]);

        expect(config('app.name'))->toBe('Tenant Console')
            ->and(env('APP_NAME'))->toBe('Legacy');
    } finally {
        putenv('APP_NAME');
        unset($_ENV['APP_NAME'], $_SERVER['APP_NAME']);
    }
});

// AT-13 — configuration.md — Configuration Caching: `config:cache` "will combine all of the
// configuration options for your application into a single file which can be quickly loaded
// by the framework". The fresh application the command bootstraps registers the providers,
// so the declared set runs and is written into the cache.
it('writes the declared values into the config cache and serves them from it', function (): void {
    $skeleton = default_skeleton_path();

    file_put_contents($skeleton.'/bootstrap/cache/testbench.yaml', "providers:\n  - ".LaravelDeclarationProvider::class."\n");

    mkdir(getcwd().'/manifest', recursive: true);
    file_put_contents(getcwd().'/manifest/app.yml', "config:\n  app:\n    name: Tenant Console\n");

    try {
        $this->artisan('config:cache')->expectsOutputToContain('Configuration cached successfully.');

        $cached = require $this->app->getCachedConfigPath();

        expect($cached['app']['name'])->toBe('Tenant Console');

        // a cache-reading application (the framework's LoadConfiguration) serves the
        // declared value from the cache — no per-request re-set required
        $cachedApp = Application::configure(basePath: $skeleton)->create();
        (new LoadConfiguration)->bootstrap($cachedApp);

        expect($cachedApp['config']->get('app.name'))->toBe('Tenant Console')
            ->and($cachedApp->make('config_loaded_from_cache'))->toBeTrue();
    } finally {
        @unlink($skeleton.'/bootstrap/cache/config.php');
        @unlink($skeleton.'/bootstrap/cache/testbench.yaml');
        @unlink(getcwd().'/manifest/app.yml');
        @rmdir(getcwd().'/manifest');
    }
});

// AT-14 — configuration.md — Configuration Caching: the cached file is what is loaded,
// and "The `config:clear` command may be used to purge the cached configuration" — a removed
// key keeps its cached value until the cache is rebuilt or cleared.
it('keeps a removed key in a stale cache and purges it with config:clear', function (): void {
    $skeleton = default_skeleton_path();

    file_put_contents($skeleton.'/bootstrap/cache/testbench.yaml', "providers:\n  - ".LaravelDeclarationProvider::class."\n");

    mkdir(getcwd().'/manifest', recursive: true);
    file_put_contents(getcwd().'/manifest/app.yml', "config:\n  app:\n    name: Tenant Console\n");

    try {
        $this->artisan('config:cache')->expectsOutputToContain('Configuration cached successfully.');

        // the manifest no longer declares config.app.name
        file_put_contents(getcwd().'/manifest/app.yml', "providers: []\n");

        // the stale cache still resolves the removed key
        $staleApp = Application::configure(basePath: $skeleton)->create();
        (new LoadConfiguration)->bootstrap($staleApp);

        expect($staleApp['config']->get('app.name'))->toBe('Tenant Console');

        $this->artisan('config:clear')->expectsOutputToContain('Configuration cache cleared successfully.');

        expect($this->app->getCachedConfigPath())->not->toBeFile();

        // after the purge the repository reflects the current manifest: the key is absent
        $freshApp = Application::configure(basePath: $skeleton)->create();
        (new LoadConfiguration)->bootstrap($freshApp);

        expect($freshApp['config']->get('app.name'))->toBe('Laravel');
    } finally {
        @unlink($skeleton.'/bootstrap/cache/config.php');
        @unlink($skeleton.'/bootstrap/cache/testbench.yaml');
        @unlink(getcwd().'/manifest/app.yml');
        @rmdir(getcwd().'/manifest');
    }
});

// AT-15 — declarative-configuration.md §2.6 (source-derived): "Values pass through as YAML
// decoded them: `true`/`false`/`null`/integers arrive typed; nothing re-evaluates strings."
// configuration.md — Environment Variable Types documents the `env()` reserved-value parsing
// the manifest path deliberately does not perform.
it('passes yaml literals through typed without env-style re-evaluation', function (): void {
    $file = $this->manifest(<<<'YAML'
        config:
          app:
            debug: true
            retries: 3
            vendor: "null"
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(config('app.debug'))->toBeTrue()
        ->and(config('app.retries'))->toBe(3)
        ->and(config('app.vendor'))->toBe('null');
});
