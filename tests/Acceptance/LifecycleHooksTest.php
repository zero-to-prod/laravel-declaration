<?php

declare(strict_types=1);

use Illuminate\Http\Client\RequestException;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\MongoStore;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\RedisEventPusher;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\HookLog;

beforeEach(function (): void {
    HookLog::reset();

    // The framework's own default, so the 240 the boot applies can only come from the
    // declared `registered` callback.
    RequestException::$truncateAt = 120;
});

afterEach(function (): void {
    RequestException::$truncateAt = 120;
});

// Section 6 of docs/declarative-application-acceptance-test-plan.md — lifecycle hooks,
// sourced from docs/repos/laravel/docs/lifecycle.md, providers.md, cache.md and
// http-client.md.

// AT-24 — http-client.md — Throwing Exceptions.
it('applies the registered behavior when the registration phase completes', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls: [{method: registered, args: [app/acceptance/registered.php]}]
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(RequestException::$truncateAt)->toBe(240)
        ->and(HookLog::entries())->toContain('registered.php');
});

// AT-25 — cache.md — Registering the Driver.
it('makes the custom driver the booting callback registered available to provider boot', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls:
          - {method: register, args: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\MongoCacheProvider]}
          - {method: booting, args: [app/acceptance/cache-extend.php]}
        YAML);

    $this->withConfig([
        'laravel-declaration.manifest' => $file,
        // The documented `config/cache.php` option naming the extension.
        'cache.stores.mongo' => ['driver' => 'mongo'],
    ]);

    expect(HookLog::entries())->toContain('mongo-driver:'.MongoStore::class);
});

// AT-26 — lifecycle.md — Service Providers / providers.md — The Boot Method.
it('resolves the declared binding in every provider boot', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls:
          - method: register
            args: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Providers\BindingConsumerProvider]
          - method: registered
            args:
              - - method: bind
                  args:
                    - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Contracts\EventPusher
                    - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Services\RedisEventPusher
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(HookLog::entries())->toContain('boot:'.RedisEventPusher::class);
});
