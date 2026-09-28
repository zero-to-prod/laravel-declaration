<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Laravel\Mcp\Server\Registrar;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\ConfigSpyProvider;

function configManifest(string $yaml): string
{
    $file = tempnam(sys_get_temp_dir(), 'manifest-').'.yml';
    file_put_contents($file, $yaml);

    return $file;
}

it('merges the declared values over the file array, YAML winning', function (): void {
    $manifest = configManifest(<<<'YAML'
        config:
          app:
            name: Tenant Console
            timezone: UTC
        YAML);

    try {
        $this->withConfig(['laravel-declaration.manifest' => $manifest]);

        expect(config('app.name'))->toBe('Tenant Console')
            // every other app.* key from the file survives
            ->and(config('app.env'))->toBe('testing');
    } finally {
        unlink($manifest);
    }
});

it('merges per top-level key, replacing nested maps whole', function (): void {
    $manifest = configManifest(<<<'YAML'
        config:
          cache:
            default: redis
          database:
            redis:
              host: redis-host
        YAML);

    try {
        $this->withConfig(['laravel-declaration.manifest' => $manifest]);

        // top-level merge: the `default` key wins, `stores` survives
        expect(config('cache.default'))->toBe('redis')
            ->and(config('cache.stores.array.driver'))->toBe('array')
            // shallow merge: the whole `redis` map is replaced, siblings survive
            ->and(config('database.redis'))->toBe(['host' => 'redis-host'])
            ->and(config('database.migrations'))->not->toBeNull();
    } finally {
        unlink($manifest);
    }
});

it('sets a dotted key as a config() path, keeping its siblings', function (): void {
    $manifest = configManifest(<<<'YAML'
        config:
          cache:
            stores.array.serialize: true
        YAML);

    try {
        $this->withConfig(['laravel-declaration.manifest' => $manifest]);

        expect(config('cache.stores.array.serialize'))->toBeTrue()
            ->and(config('cache.stores.array.driver'))->toBe('array');
    } finally {
        unlink($manifest);
    }
});

it('configures this package before its own boot', function (): void {
    $manifest = configManifest(<<<'YAML'
        config:
          laravel-declaration:
            mcp.enabled: false
        YAML);

    try {
        $this->withConfig(['laravel-declaration.manifest' => $manifest]);

        expect($this->app->make(Registrar::class)->getLocalServer('laravel-declaration'))->toBeNull();
    } finally {
        unlink($manifest);
    }
});

it('gains keys no file declares, with typed YAML values', function (): void {
    $manifest = configManifest(<<<'YAML'
        config:
          sentinel:
            meters: true
            limit: 10
        YAML);

    try {
        $this->withConfig(['laravel-declaration.manifest' => $manifest]);

        expect(config('sentinel.meters'))->toBeTrue()
            ->and(config('sentinel.limit'))->toBe(10)
            ->and(Config::get('sentinel'))->toBe(['meters' => true, 'limit' => 10]);
    } finally {
        unlink($manifest);
    }
});

it('merges nothing when the manifest has no config block', function (): void {
    $manifest = configManifest(<<<'YAML'
        providers:
          - class: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\AppServiceProvider
        YAML);

    try {
        $this->withConfig(['laravel-declaration.manifest' => $manifest]);

        expect(config('app.name'))->toBe('Laravel')
            ->and(Config::get('sentinel'))->toBeNull();
    } finally {
        unlink($manifest);
    }
});

it('merges nothing without a manifest', function (): void {
    expect(config('app.name'))->toBe('Laravel')
        ->and(Config::get('sentinel'))->toBeNull();
});

it('declared providers see the merged values in register and boot', function (): void {
    $manifest = configManifest(<<<'YAML'
        config:
          app:
            name: Tenant Console
        providers:
          - class: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\ConfigSpyProvider
        YAML);

    try {
        $this->withConfig(['laravel-declaration.manifest' => $manifest]);

        expect(ConfigSpyProvider::$seen)->toBe([
            'register' => 'Tenant Console',
            'boot' => 'Tenant Console',
        ]);
    } finally {
        unlink($manifest);
        ConfigSpyProvider::$seen = [];
    }
});

it('rejects a scalar under a file key', function (): void {
    $manifest = configManifest(<<<'YAML'
        config:
          app: "foo"
        YAML);

    try {
        $this->withConfig(['laravel-declaration.manifest' => $manifest]);
    } finally {
        unlink($manifest);
    }
})->throws(LogicException::class, 'The `config.app` entry must be a map of config keys.');
