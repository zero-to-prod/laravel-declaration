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

    /** The manifest skeleton: a `calls` body on Illuminate\Foundation\Application, with the lifecycle methods as section markers. */
    public static function manifest(): string
    {
        return <<<'YAML'
            # The manifest is a list of calls on Illuminate\Foundation\Application, applied in order
            # when the package registers. Each node is one PHP call: `method`, its `args` in order,
            # and `then` for a `->` chain on the return. Timing is written with the application's
            # own lifecycle methods.

            calls:
              - method: make                          # make(Illuminate\Config\Repository)->set([...])
                args: [Illuminate\Config\Repository]
                then:
                  - method: set
                    args: [{}]                        # {app.name: Tenant Console}

              - method: registered                    # a body on the application once every provider has registered
                args:
                  - []                                # - {method: bind, args: [App\Contracts\Pdf, App\Services\DomPdf]}
                                                      # - {method: register, args: [App\Providers\AppServiceProvider]}

              - method: afterResolving                # a body on each service when it first resolves
                args:
                  - Illuminate\Routing\Router
                  - []                                # - {method: addRoute, args: [GET, /, App\Http\HomeController], then: [{method: name, args: [home]}]}

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
