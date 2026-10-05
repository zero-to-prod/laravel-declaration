<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal;

use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/** @internal */
final class Installer
{
    public static function path(): string
    {
        return config_path('laravel-declaration.php');
    }

    /** The manifest file the configuration names, under the application's base path. */
    public static function manifestPath(): string
    {
        return base_path(Config::string('laravel-declaration.manifest', 'manifest/app.yml'));
    }

    public static function configuration(bool $mcp, string $handle): string
    {
        return str_replace([
            "'enabled' => true,",
            "'handle' => 'laravel-declaration',",
        ], [
            "'enabled' => ".var_export($mcp, true).',',
            "'handle' => ".var_export($handle, true).',',
        ], File::get(dirname(__DIR__, 2).'/config/laravel-declaration.php'));
    }

    /** The manifest skeleton: a body on Illuminate\Foundation\Application, with the lifecycle keys as section markers. */
    public static function manifest(): string
    {
        return <<<'YAML'
            # yaml-language-server: $schema=./../vendor/zero-to-prod/laravel-declaration/manifest.schema.json
            #
            # The manifest is a body on Illuminate\Foundation\Application: every root key is one of its
            # methods, applied in order when the package registers. Timing is written with the
            # application's own lifecycle methods.

            make:                                   # services already resolved at register() (config)
              Illuminate\Config\Repository:
                set: {}                             # app.name: Tenant Console

            registered:                             # a body on the application once every provider has registered
              bind: {}                              # App\Contracts\Pdf: App\Services\DomPdf
              singleton: []                         # - App\Services\TenantContext

            register: []                            # - App\Providers\AppServiceProvider

            afterResolving:                         # a body on each service when it first resolves
              Illuminate\Routing\Router:
                addRoute: []                        # - {methods: GET, uri: /, action: App\Http\HomeController, name: home}

            YAML;
    }

    /**
     * @param  Closure(): bool  $overwrite  Consulted only when the file on disk says something else
     * @return 'created'|'unchanged'|'updated'|'kept'
     */
    public static function write(string $contents, Closure $overwrite): string
    {
        $file = self::path();
        $status = match (true) {
            ! File::exists($file) => 'created',
            File::get($file) === $contents => 'unchanged',
            $overwrite() => 'updated',
            default => 'kept',
        };

        if ($status === 'created' || $status === 'updated') {
            File::ensureDirectoryExists(dirname($file));
            File::put($file, $contents);
        }

        return $status;
    }

    /**
     * Writes the manifest only when none exists — an existing manifest is the application's and is never touched.
     *
     * @return 'created'|'kept'
     */
    public static function writeManifest(string $contents): string
    {
        $file = self::manifestPath();

        if (File::exists($file)) {
            return 'kept';
        }

        File::ensureDirectoryExists(dirname($file));
        File::put($file, $contents);

        return 'created';
    }
}
