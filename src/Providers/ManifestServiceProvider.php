<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Yaml\Yaml;
use ZeroToProd\LaravelDeclaration\Internal\Engine\Engine;
use ZeroToProd\LaravelDeclaration\Internal\Engine\Resolve;
use ZeroToProd\LaravelDeclaration\Internal\ManifestStore;

/**
 * The only dispatch call site in the package: the manifest is a body on the application, applied in `register()`.
 * Timing is written in the manifest with the application's own lifecycle methods (`registered`, `booting`,
 * `booted`, `afterResolving`, `make`), so this provider has no `boot()`.
 *
 * @internal
 */
class ManifestServiceProvider extends ServiceProvider
{
    /** @var array<string, mixed>|null the decoded schema, read once per process */
    private static ?array $schema = null;

    public function register(): void
    {
        $store = new ManifestStore($this->manifest(Config::string('laravel-declaration.manifest', 'manifest/app.yml')));

        $this->app->instance(ManifestStore::class, $store);

        $engine = new Engine(self::schema(), $this->app, new Resolve($this->app));

        $this->app->instance(Engine::class, $engine);

        $engine->body($this->app, $store->all());
    }

    /** @return array<string, mixed> the decoded manifest.schema.json — the only validation layer and the runtime's signature source */
    public static function schema(): array
    {
        if (self::$schema === null) {
            /** @var array<string, mixed> $schema */
            $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/manifest.schema.json'), true, 512, JSON_THROW_ON_ERROR);

            self::$schema = $schema;
        }

        return self::$schema;
    }

    /** @return array<string, mixed> */
    private function manifest(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }

        $manifest = Yaml::parseFile($file);

        return is_array($manifest) ? $manifest : [];
    }
}
